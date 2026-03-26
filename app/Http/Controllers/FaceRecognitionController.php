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
                        'success' => false,
                        'code' => 'ERROR',
                        'message' => 'No se pudo ejecutar el script de Python.',
                        'data' => null
                    ], 500);
                }

                // Decodificar el JSON retornado por el script Python
                $decoded = json_decode($output, true);
                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    return response()->json([
                        'success' => false,
                        'code' => 'ERROR',
                        'message' => 'Error al ejecutar el script de Python.',
                        'data' => null
                    ], 500);
                }

                $isMinTimeError = isset($decoded['error_code']) && $decoded['error_code'] === 'MIN_TIME_NOT_MET';

                // Si la coincidencia es verdadera y no hay un error de tiempo mínimo, validar contra CRONODE API
                if (isset($decoded['coincidencia']) && $decoded['coincidencia'] === true && !$isMinTimeError) {
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
                            $activeScheduleDetails = null;
                            $responseData = $response->json();
                            $schedules = $responseData['data']['Schedules'] ?? [];
                            $ambientName = $responseData['data']['name'] ?? 'Ambiente';
                            $now = Carbon::now('America/Bogota');
                            $currentDay = $now->dayOfWeekIso;

                            if (is_array($schedules)) {
                                if (isset($schedules['startDate'])) {
                                    $schedules = [$schedules];
                                }

                                foreach ($schedules as $schedule) {
                                    if (!is_array($schedule)) continue;
                                    if (!isset($schedule['startDate']) || !isset($schedule['endDate'])) continue;

                                    $startDate = Carbon::parse($schedule['startDate']);
                                    $endDate = Carbon::parse($schedule['endDate']);

                                    if ($now->between($startDate, $endDate)) {
                                        $days = $schedule['day'] ?? [];
                                        if (!is_array($days)) $days = [$days];

                                        if (in_array($currentDay, $days)) {
                                            if (isset($schedule['startHour']) && isset($schedule['endHour'])) {
                                                // Buffer de 2 horas para permitir ingresos
                                                $startHour = Carbon::createFromTimeString($schedule['startHour'], 'America/Bogota')->subHours(2);
                                                $endHour = Carbon::createFromTimeString($schedule['endHour'], 'America/Bogota')->addHours(2);

                                                if ($now->between($startHour, $endHour)) {
                                                    $constantUserId = $schedule['ConstantUserId'] ?? null;
                                                    $scheduleUsername = $schedule['ConstantUser']['username'] ?? null;

                                                    if ($constantUserId == $userId || $scheduleUsername === $userName) {
                                                        $hasActiveClass = true;
                                                        
                                                        // Capturar detalles del horario para la respuesta
                                                        $docente = $schedule['ConstantUser']['username'] ?? 'Docente';
                                                        $clase = $schedule['Programation']['Group']['FormationProgram']['name'] ?? 'Clase';
                                                        $horarioStr = substr($schedule['startHour'], 0, 5) . ' - ' . substr($schedule['endHour'], 0, 5);
                                                        
                                                        $activeScheduleDetails = [
                                                            'ambient' => $ambientName,
                                                            'status' => 'Ocupado',
                                                            'docente' => $docente,
                                                            'clase' => $clase,
                                                            'horario' => $horarioStr,
                                                            'full_message' => "{$ambientName} Ocupado | Docente: {$docente} | Clase: {$clase} | Horario: {$horarioStr}"
                                                        ];
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            if (!$hasActiveClass) {
                                return response()->json([
                                    'success' => false,
                                    'code' => 'NO_CLASS',
                                    'message' => "Acceso denegado: El docente no tiene clase programada en {$ambientName} a esta hora.",
                                    'data' => [
                                        'id' => $userId,
                                        'nombre' => $userName,
                                        'distancia' => $decoded['distancia'] ?? null,
                                        'tipo_evento' => null,
                                        'hasClass' => false,
                                        'ambient_name' => $ambientName
                                    ]
                                ], 403);
                            }

                            // Si tiene clase, agregamos los detalles a la respuesta
                            $decoded['hasClass'] = true;
                            $decoded['message'] = $activeScheduleDetails['full_message'] ?? "Bienvenido. Tienes clase asignada.";
                            $decoded['schedule_data'] = $activeScheduleDetails;

                        } else {
                            // Si la API responde con un error HTTP
                            return response()->json([
                                'success' => false,
                                'code' => 'ERROR',
                                'message' => 'Error al conectarse con CRONODE API.',
                                'data' => null
                            ], 502);
                        }
                    } catch (Exception $e) {
                        return response()->json([
                            'success' => false,
                            'code' => 'ERROR',
                            'message' => 'Excepción de red al contactar CRONODE.',
                            'data' => null
                        ], 502);
                    }
                }

                // Construir la estructura estandarizada de respuesta
                $success = false;
                $code = 'ERROR';
                $message = 'Error desconocido';

                // Prioridad a códigos de error explícitos retornados por el script Python
                $errorCode = $decoded['error_code'] ?? null;

                if (in_array($errorCode, ['NO_FACE_DETECTED_IN_IMAGE', 'IMAGE_READ_ERROR', 'NO_VALID_REGISTERED_FACES'])) {
                    $code = 'NO_FACE';
                    $message = $decoded['error'] ?? 'No se detectó ningún rostro válido para comparar.';
                } elseif ($errorCode === 'MIN_TIME_NOT_MET') {
                    $code = 'MIN_TIME';
                    $message = $decoded['error'] ?? 'Tiempo mínimo entre registros no cumplido.';
                } elseif (array_key_exists('coincidencia', $decoded)) {
                    if ($decoded['coincidencia'] === true) {
                        $success = true;
                        $code = 'ACCESS_GRANTED';
                        $message = $decoded['message'] ?? 'Acceso concedido';
                    } else {
                        // coincidencia === false: rostro detectado pero sin coincidencia en la BD
                        $code = 'NO_MATCH';
                        $message = 'Rostro detectado, pero no corresponde a nadie en la base de datos.';
                    }
                } elseif ($errorCode !== null) {
                    // Otros error_code no mapeados
                    $code = 'ERROR';
                    $message = $decoded['error'] ?? 'Error interno al procesar el rostro.';
                } elseif (isset($decoded['error'])) {
                    // Fallback: error sin error_code
                    $code = 'ERROR';
                    $message = $decoded['error'];
                }

                $data = [
                    'id' => $decoded['id'] ?? null,
                    'nombre' => $decoded['nombre'] ?? null,
                    'distancia' => $decoded['distancia'] ?? null,
                    'tipo_evento' => $decoded['tipo_evento'] ?? null,
                    'hasClass' => $decoded['hasClass'] ?? null,
                    'tiempo_restante' => $decoded['tiempo_restante'] ?? null
                ];

                return response()->json([
                    'success' => $success,
                    'code' => $code,
                    'message' => $message,
                    'data' => $data
                ]);
            } catch (Exception $e) {
                return response()->json([
                    'success' => false,
                    'code' => 'ERROR',
                    'message' => $e->getMessage(),
                    'data' => null
                ], 500);
            } finally {
                if (isset($path)) {
                    Storage::delete($path);
                }
            }
        }

        return response()->json([
            'success' => false,
            'code' => 'ERROR',
            'message' => 'No se proporcionó ninguna imagen',
            'data' => null
        ], 400);
    }
}
