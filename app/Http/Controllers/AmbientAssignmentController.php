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
            $devices = Device::pluck('ambient_id');

            // filtramos solo los ambientes que están asignados a un dispositivo
            $ambientes_asignados = collect($res->json('data'))
                ->filter(fn($a) => $devices->contains($a['id']))
                ->values();

            // Cargas las coordenadas que existan en tu tabla local
            $settings = \App\Models\AmbientSetting::whereIn('ambient_id', $ambientes_asignados->pluck('id'))->get();

            $now = \Carbon\Carbon::now('America/Bogota');
            $currentDay = $now->dayOfWeekIso;

            // Mapeo iterando cada ambiente para cruzarle "x" e "y" y detalles de ocupación
            $data = $ambientes_asignados->map(function ($amb) use ($settings, $baseUrl, $apiKey, $now, $currentDay) {
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
                    $now = \Carbon\Carbon::now('America/Bogota');
                    $currentDate = $now->toDateString();
                    
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
                    // Silently fail or log error
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

            return response()->json($data);
        }

        return response()->json(['message' => 'No se encontraron ambientes'], 404);
    }

}
