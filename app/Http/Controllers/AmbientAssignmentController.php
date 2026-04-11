<?php

namespace App\Http\Controllers;

use App\Models\Ambient_assignment;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AmbientAssignmentController extends Controller
{



    public function ambients()
    {
        $baseUrl = config('app.api.url');
        $apiKey = config('app.api.key');

        $res = Http::withHeaders([
            'x-api-key' => $apiKey
        ])->get($baseUrl . 'api/v1/ambients');

        if ($res->successful()) {
            // dispositivos asignados
            $devices = \App\Models\Device::pluck('ambient_id');

            // filtramos solo los ambientes que están asignados a un dispositivo
            $ambientes_asignados = collect($res->json('data'))
                ->filter(fn($a) => $devices->contains($a['id']))
                ->values();

            // Cargas las coordenadas que existan en tu tabla local
            $settings = \App\Models\AmbientSetting::whereIn('ambient_id', $ambientes_asignados->pluck('id'))->get();

            $now = \Carbon\Carbon::now('America/Bogota');
            $currentDay = $now->dayOfWeekIso;
            $currentDate = $now->toDateString();

            // Variables para estadísticas globales
            $totalTentative = 0;
            $totalPresent = 0;
            $activeSchedules = collect();

            // Mapeo iterando cada ambiente para cruzarle "x" e "y" y detalles de ocupación
            $data = $ambientes_asignados->map(function ($amb) use ($settings, $baseUrl, $apiKey, $now, $currentDay, $currentDate, &$activeSchedules) {
                $setting = $settings->firstWhere('ambient_id',  $amb['id']);

                if ($setting) {
                    $amb['x'] = (float) $setting->x_coordinate;
                    $amb['y'] = (float) $setting->y_coordinate;
                }

                // Estructura por defecto para validar en el frontend
                $amb['docente'] = null;
                $amb['ficha'] = null;
                $amb['clase'] = null;
                $amb['horario'] = null;
                $amb['schedule_id'] = null;
                $amb['extraordinary'] = false;
                $amb['extraordinary_message'] = null;
                $amb['break_time'] = false;

                try {
                    // 1. Intentar encontrar la clase oficial de HOY en su ventana de ±20 min
                    $activeSchedule = \App\Models\AmbientSchedule::where('ambient_id', $amb['id'])
                        ->where('date', $currentDate)
                        ->get()
                        ->filter(function($schedule) use ($now) {
                            $start = \Carbon\Carbon::parse($schedule->start_time, 'America/Bogota')->subMinutes(20);
                            $end = \Carbon\Carbon::parse($schedule->end_time, 'America/Bogota')->addMinutes(20);
                            return $now->between($start, $end);
                        })->first();

                    // 2. FALLBACK (Para Pruebas): Si no hay clase hoy en ventana, buscar 
                    // cualquier registro que esté ABIERTO (sin importar la fecha)
                    if (!$activeSchedule) {
                        $activeSchedule = \App\Models\AmbientSchedule::where('ambient_id', $amb['id'])
                            ->whereNotNull('open_by')
                            ->whereNull('closed_by')
                            ->first();
                    }

                    if ($activeSchedule) {
                        $activeSchedules->push($activeSchedule);
                        
                        $amb['schedule_id'] = $activeSchedule->id;
                        $docente = $activeSchedule->teacher_name ?? 'No asignado';
                        $ficha = $activeSchedule->codeTab ?? 'Sin ficha';
                        $clase = $activeSchedule->class ?? 'Sin nombre de clase';
                        
                        $startFormat = \Carbon\Carbon::parse($activeSchedule->start_time)->format('H:i');
                        $endFormat = \Carbon\Carbon::parse($activeSchedule->end_time)->format('H:i');
                        $horario = $startFormat . ' - ' . $endFormat;
                        
                        $amb['docente'] = $docente;
                        $amb['ficha'] = $ficha;
                        $amb['clase'] = $clase;
                        $amb['horario'] = $horario;
                        $amb['full_status'] = "Docente: {$docente} | Ficha: {$ficha} | Clase: {$clase} | Horario: {$horario}";

                        // Validación de asignación extraordinaria
                        if (!is_null($activeSchedule->open_by) && $activeSchedule->open_by != $activeSchedule->user_id) {
                            $amb['extraordinary'] = true;
                            $amb['extraordinary_message'] = "La asignación de este ambiente fue cambiada extraordinariamente";
                        }

                        $amb['break_time'] = $activeSchedule->break_time;
                    }
                } catch (\Exception $e) {
                    $amb['status_text'] = "Error logico local";
                }

                // Resolviendo status text en base a CRONODE
                if ($amb['isOccupied'] ?? false) {
                    if (isset($amb['schedule_id'])) {
                        $amb['status_text'] = "Ocupado";
                    } else {
                        $amb['status_text'] = "Ocupado (Sin detalles)";
                    }
                } else {
                    $amb['status_text'] = "Disponible (ok)";
                }

                return $amb;
            })->toArray();

            // Ordenamiento por ID
            usort($data, function ($a, $b) {
                return $a['id'] <=> $b['id'];
            });

            // 1. Identificar todos los ambientes registrados
            $ambientIds = $devices->toArray();
            
            // 2. Buscar TODAS las clases que están estrictamente "en curso" en este momento
            // Usamos el tiempo actual sin el margen de 20 minutos para que las estadísticas sean precisas
            $currentTime = $now->toTimeString();
            $strictlyActiveSchedules = \App\Models\AmbientSchedule::whereIn('ambient_id', $ambientIds)
                ->where('date', $currentDate)
                ->where('start_time', '<=', $currentTime)
                ->where('end_time', '>=', $currentTime)
                ->get();

            if ($strictlyActiveSchedules->isNotEmpty()) {
                $activeScheduleIds = $strictlyActiveSchedules->pluck('id');
                $activeFichas = $strictlyActiveSchedules->pluck('codeTab')->filter()->unique();

                // TENTATIVE: Suma de integrantes esperados en los grupos de las clases en curso
                $totalTentative = \App\Models\Group::whereIn('code_tab', $activeFichas)
                    ->withCount('users')
                    ->get()
                    ->sum('users_count');

                // PRESENT: Estudiantes presentes + Docentes con sesión abierta
                $presentStudents = \App\Models\Attendance::whereIn('ambient_schedule_id', $activeScheduleIds)
                    ->whereIn('id', function($query) use ($activeScheduleIds) {
                        $query->selectRaw('MAX(id)')
                            ->from('attendances')
                            ->whereIn('ambient_schedule_id', $activeScheduleIds)
                            ->groupBy('user_id', 'ambient_schedule_id');
                    })
                    ->where('event_type', 'entry')
                    ->count();
                
                $presentTeachers = $strictlyActiveSchedules->filter(fn($s) => !is_null($s->open_by) && is_null($s->closed_by))->count();

                $totalPresent = $presentStudents + $presentTeachers;
            }

            return response()->json([
                'stats' => [
                    'tentative' => (int) $totalTentative,
                    'present'   => (int) $totalPresent,
                ],
                'ambients' => $data
            ]);
        }

        return response()->json(['message' => 'No se encontraron ambientes'], 404);
    }

    /**
     * Obtiene los ambientes desde CRONODE que aún no están asignados localmente.
     */
    public function cronodeAmbients()
    {
        $baseUrl = config('app.api.url');
        $apiKey = config('app.api.key');

        $res = Http::withHeaders([
            'x-api-key' => $apiKey
        ])->get($baseUrl . 'api/v1/ambients');

        if ($res->successful()) {
            $cronodeAmbients = collect($res->json('data'));
            
            // Obtener IDs de ambientes ya registrados localmente
            $localAmbientIds = Device::pluck('ambient_id');

            // Filtrar para dejar solo los que NO están registrados
            $availableAmbients = $cronodeAmbients->filter(function($amb) use ($localAmbientIds) {
                return !$localAmbientIds->contains($amb['id']);
            })->values();

            return response()->json($availableAmbients);
        }

        return response()->json(['message' => 'No se pudo conectar con CRONODE'], 500);
    }

    /**
     * Registra un nuevo ambiente localmente (crea un Device).
     */
    public function storeDevice(Request $request)
    {
        $validated = $request->validate([
            'ambient_id' => 'required|integer|unique:devices,ambient_id',
            'name'       => 'required|string|max:255',
            'ip_address' => 'required|ip',
        ]);

        $device = Device::create([
            'ambient_id' => $validated['ambient_id'],
            'name'       => $validated['name'],
            'ip_address' => $validated['ip_address'],
            'status'     => 1,
        ]);

        return response()->json([
            'message' => 'Ambiente registrado correctamente',
            'data'    => $device
        ], 201);
    }

    /**
     * Elimina el registro local de un ambiente (borra el Device).
     */
    public function destroyDevice($ambientId)
    {
        $device = Device::where('ambient_id', $ambientId)->first();

        if (!$device) {
            return response()->json(['message' => 'Ambiente no encontrado'], 404);
        }

        $device->delete();

        return response()->json(['message' => 'Ambiente eliminado correctamente']);
    }
}
