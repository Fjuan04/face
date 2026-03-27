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

                // Si está ocupado, buscamos los detalles del horario actual en DB local
                if ($amb['isOccupied'] ?? false) {
                    try {
                        $now = \Carbon\Carbon::now('America/Bogota');
                        $currentDate = $now->toDateString();
                        
                        $activeSchedule = \App\Models\AmbientSchedule::where('ambient_id', $amb['id'])
                            ->where('date', $currentDate)
                            ->get()
                            ->filter(function($schedule) use ($now) {
                                $start = \Carbon\Carbon::parse($schedule->start_time, 'America/Bogota')->subHours(3);
                                $end = \Carbon\Carbon::parse($schedule->end_time, 'America/Bogota')->addHours(3);
                                return $now->between($start, $end);
                            })->first();

                        if ($activeSchedule) {
                            $docente = $activeSchedule->teacher_name ?? 'No asignado';
                            $ficha = $activeSchedule->codeTab ?? 'Sin ficha';
                            $clase = $activeSchedule->class ?? 'Sin nombre de clase';
                            
                            $startFormat = \Carbon\Carbon::parse($activeSchedule->start_time)->format('H:i');
                            $endFormat = \Carbon\Carbon::parse($activeSchedule->end_time)->format('H:i');
                            $horario = $startFormat . ' - ' . $endFormat;
                            
                            $amb['status_text'] = "Ocupado";
                            $amb['docente'] = $docente;
                            $amb['ficha'] = $ficha;
                            $amb['clase'] = $clase;
                            $amb['horario'] = $horario;
                            $amb['full_status'] = "Docente: {$docente} | Ficha: {$ficha} | Clase: {$clase} | Horario: {$horario}";
                        } else {
                            $amb['status_text'] = "Ocupado (Sin detalles)";
                        }
                    } catch (\Exception $e) {
                        // Silently fail or log error
                        $amb['status_text'] = "Ocupado (Error logico local)";
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
