<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class FaceRecognitionController extends Controller
{
    public function testView()
    {
        return view('reconocer.test');
    }

    /**
     * Procesa una imagen enviada por la ESP32 / vista de prueba
     * y delega el reconocimiento al script de Python (reconocer.py).
     *
     * Espera un request con:
     * - imagen: Archivo de imagen
     * - ambient_id: ID del ambiente (enviado por la ESP32)
     */
    public function process(Request $request)
    {
        // Validamos que venga la imagen y el ambient_id
        $request->validate([
            'imagen' => 'required|file',
            'ambient_id' => 'required'
        ]);

        if ($request->hasFile('imagen')) {

            try {
                $photo = $request->file('imagen');
                $ambientId = $request->input('ambient_id');

                $path = $photo->store('tmp');
                $fullPath = Storage::path($path);

                // detectar OS y construir comando para shell_exec
                $python = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                    ? base_path('services/face-recognition/venv/Scripts/python.exe')
                    : base_path('services/face-recognition/venv/bin/python');

                $script = base_path('services/face-recognition/src/reconocer.py');

                // Envolvemos rutas entre comillas para manejar espacios
                $command = sprintf('"%s" "%s" "%s"', $python, $script, $fullPath);

                // Capturamos también stderr
                $output = shell_exec($command . ' 2>&1');

                if ($output === null) {
                    return response()->json([
                        'error' => 'No se pudo ejecutar el script de Python.'
                    ], 500);
                }

                // El script siempre imprime JSON; si no es JSON, tratamos como error
                $decoded = json_decode($output, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return response()->json([
                        'error' => 'Error al ejecutar el script de Python.',
                        'raw_output' => $output,
                    ], 500);
                }

                // Si la coincidencia es verdadera, validar contra CRONODE API
                if (isset($decoded['coincidencia']) && $decoded['coincidencia'] === true) {
                    $userId = $decoded['id'] ?? null;
                    $userName = $decoded['nombre'] ?? null;

                    $cronodeBaseUrl = env('API_BASE_URL', 'http://ejemplo-cronode.com');
                    $cronodeApiKey = env('API_KEY', 'default_key');

                    try {
                        $response = Http::withHeaders([
                            'x-api-key' => $cronodeApiKey,
                        ])->timeout(5)->get("{$cronodeBaseUrl}api/v1/ambients/ambientSchedule/{$ambientId}");

                        if ($response->successful()) {
                            $hasActiveClass = false;
                            $responseData = $response->json();
                            $schedules = $responseData['data']['Schedules'] ?? [];
                            $now = Carbon::now('America/Bogota');
                            $currentDay = $now->dayOfWeekIso; // 1 = Lunes ... 7 = Domingo

                            if (is_array($schedules)) {
                                // Si la API devuelve un mensaje de error como {"message": "Algo falló"}
                                if (isset($schedules['message'])) {
                                    // Ignoramos y dejamos que falle la validación (hasActiveClass = false)
                                } else {
                                    // Algunas veces CRONODE puede devolver un solo objeto en lugar de un array
                                    // Si es asociativo y tiene 'startDate', lo envolvemos en un array de 1 elemento
                                    if (isset($schedules['startDate'])) {
                                        $schedules = [$schedules];
                                    }

                                    foreach ($schedules as $schedule) {
                                        if (!is_array($schedule)) {
                                            continue;
                                        }

                                        if (!isset($schedule['startDate']) || !isset($schedule['endDate'])) {
                                            continue;
                                        }

                                        $startDate = Carbon::parse($schedule['startDate']);
                                        $endDate = Carbon::parse($schedule['endDate']);

                                        // Validación 1: Fecha actual entre startDate y endDate
                                        if ($now->between($startDate, $endDate)) {
                                            
                                            // Validación 2: Día de la semana coincide
                                            $days = $schedule['day'] ?? [];
                                            if (!is_array($days)) {
                                                $days = [$days];
                                            }

                                            if (in_array($currentDay, $days)) {
                                                
                                                // Validación 3: Hora actual entre startHour (-2 horas) y endHour (+2 horas)
                                                if (isset($schedule['startHour']) && isset($schedule['endHour'])) {
                                                    $startHour = Carbon::createFromTimeString($schedule['startHour'], 'America/Bogota')->subHours(2);
                                                    $endHour = Carbon::createFromTimeString($schedule['endHour'], 'America/Bogota')->addHours(2);
                                                    
                                                    // $now->between(...) maneja la hora actual directamente
                                                    if ($now->between($startHour, $endHour)) {
                                                        
                                                        // Validación 4: Que el docente sea el reconocido
                                                        $constantUserId = $schedule['ConstantUserId'] ?? null;
                                                        $scheduleUsername = $schedule['ConstantUser']['username'] ?? null;

                                                        if ($constantUserId == $userId || $scheduleUsername === $userName) {
                                                            $hasActiveClass = true;
                                                            break; // Todo validado, tiene clase
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if (!$hasActiveClass) {
                                return response()->json([
                                    'error' => 'Acceso denegado: El docente no tiene clase programada en este ambiente a esta hora.',
                                    'coincidencia' => true,
                                    'hasClass' => false,
                                    'reconocimiento' => [
                                        'id' => $userId,
                                        'nombre' => $userName,
                                        'distancia' => $decoded['distancia'] ?? null
                                    ]
                                ], 403);
                            }

                            // Si tiene clase, agregamos hasClass = true a la respuesta del script
                            $decoded['hasClass'] = true;
                            $decoded['message'] = "Bienvenido, " . ($userName ?? 'Docente') . ". Tienes clase asignada.";

                        } else {
                            // Si la API responde con un error HTTP
                            return response()->json([
                                'error' => 'Error al conectarse con CRONODE API.',
                                'status' => $response->status()
                            ], 502);
                        }
                    } catch (Exception $e) {
                        return response()->json([
                            'error' => 'Excepción de red al contactar CRONODE.',
                            'detalles' => $e->getMessage()
                        ], 502);
                    }
                }

                // Devolver directamente el JSON decodificado como respuesta limpia (Acceso Concedido)
                return response()->json($decoded);
            } catch (Exception $e) {
                return response()->json([
                    "error" => $e->getMessage()
                ], 500);
            } finally {
                if (isset($path)) {
                    Storage::delete($path);
                }
            }
        }

        return response()->json(['error' => 'No se proporcionó ninguna imagen'], 400);
    }
}
