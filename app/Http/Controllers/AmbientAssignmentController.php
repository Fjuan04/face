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

                // Si está ocupado, buscamos los detalles del horario actual
                if ($amb['isOccupied'] ?? false) {
                    try {
                        $schedRes = Http::withHeaders(['x-api-key' => $apiKey])
                            ->timeout(3)
                            ->get("{$baseUrl}api/v1/ambients/ambientSchedule/{$amb['id']}");
                        
                        if ($schedRes->successful()) {
                            $schedules = $schedRes->json('data.Schedules') ?? [];
                            $activeSchedule = null;

                            foreach ($schedules as $schedule) {
                                $startDate = \Carbon\Carbon::parse($schedule['startDate']);
                                $endDate = \Carbon\Carbon::parse($schedule['endDate']);
                                
                                if ($now->between($startDate, $endDate)) {
                                    $days = $schedule['day'] ?? [];
                                    if (!is_array($days)) $days = [$days];

                                    if (in_array($currentDay, $days)) {
                                        $startHour = \Carbon\Carbon::createFromTimeString($schedule['startHour'], 'America/Bogota');
                                        $endHour = \Carbon\Carbon::createFromTimeString($schedule['endHour'], 'America/Bogota');

                                        if ($now->between($startHour, $endHour)) {
                                            $activeSchedule = $schedule;
                                            break;
                                        }
                                    }
                                }
                            }

                            if ($activeSchedule) {
                                $docente = $activeSchedule['ConstantUser']['username'] ?? 'No asignado';
                                $clase = $activeSchedule['Programation']['Group']['FormationProgram']['name'] ?? 'Sin nombre de clase';
                                $horario = substr($activeSchedule['startHour'], 0, 5) . ' - ' . substr($activeSchedule['endHour'], 0, 5);
                                
                                $amb['status_text'] = "Ocupado";
                                $amb['docente'] = $docente;
                                $amb['clase'] = $clase;
                                $amb['horario'] = $horario;
                                $amb['full_status'] = "Docente: {$docente} | Clase: {$clase} | Horario: {$horario}";
                            } else {
                                $amb['status_text'] = "Ocupado (Sin detalles)";
                            }
                        }
                    } catch (\Exception $e) {
                        // Silently fail or log error
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


    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $ambients = Ambient_assignment::all();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Ambient_assignment $ambient_assignment)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Ambient_assignment $ambient_assignment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Ambient_assignment $ambient_assignment)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ambient_assignment $ambient_assignment)
    {
        //
    }
}
