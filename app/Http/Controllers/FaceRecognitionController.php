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
                    try {
                        if ($request->has('simulated_time') && !empty($request->input('simulated_time'))) {
                            $now = Carbon::parse($request->input('simulated_time'), 'America/Bogota');
                        } else {
                            $now = Carbon::now('America/Bogota');
                        }
                        
                        $currentDate = $now->toDateString();

                        $schedules = \App\Models\AmbientSchedule::where('ambient_id', $ambientId)
                            ->where('date', $currentDate)
                            ->get();

                        $hasActiveClass = false;
                        $activeScheduleDetails = null;
                        $activeSchedule = null;
                        $tipoEvento = 'entry';
                        $isOccupied = true;

                        // Helper: verificar si el usuario tiene permiso para un schedule
                        $isPermittedFn = function($schedule) use ($userId) {
                            return ($schedule->user_id == $userId) ||
                                   ($schedule->admin_permission == 1 && $schedule->user_allowed == $userId);
                        };

                        // Helper: construir detalles de la clase activa
                        $buildDetails = function($schedule) use ($ambientId, $userName) {
                            $horarioStr = Carbon::parse($schedule->start_time)->format('H:i') . ' - ' . Carbon::parse($schedule->end_time)->format('H:i');
                            $docente = $userName ?? 'Docente';
                            $ficha   = $schedule->codeTab ?? 'Ficha';
                            $clase   = $schedule->class   ?? 'Clase';
                            return [
                                'ambient'      => "Ambiente {$ambientId}",
                                'status'       => 'Ocupado',
                                'docente'      => $docente,
                                'ficha'        => $ficha,
                                'clase'        => $clase,
                                'horario'      => $horarioStr,
                                'full_message' => "Ambiente {$ambientId} Ocupado | Docente: {$docente} | Ficha: {$ficha} | Clase: {$clase} | Horario: {$horarioStr}",
                            ];
                        };

                        // === PASO 1: ¿Hay alguna clase abierta (sin cerrar) para este usuario? ===
                        $openSession = null;
                        foreach ($schedules as $schedule) {
                            if ($isPermittedFn($schedule) && !is_null($schedule->open_by) && is_null($schedule->closed_by)) {
                                $openSession = $schedule;
                                break;
                            }
                        }

                        if ($openSession) {
                            $hasActiveClass = true;
                            $activeSchedule = $openSession;
                            $activeScheduleDetails = $buildDetails($openSession);

                            if ($openSession->break_time == 1 && is_null($openSession->end_break)) {
                                // === Retorno de descanso ===
                                $openSession->end_break = $now;
                                $openSession->updated_at = $now;
                                $openSession->save();
                                $tipoEvento = 'entry';
                                $isOccupied = true;
                            } else {
                                // === Cierre de sesión abierta ===
                                $openSession->closed_by = $userId;
                                $openSession->updated_at = $now;
                                $openSession->save();

                                \App\Models\Event::create([
                                    'user_id'    => $userId,
                                    'device_id'  => 1,
                                    'ambient_id' => $ambientId,
                                    'event_type' => 'exit',
                                    'created_at' => $now,
                                    'updated_at' => $now,
                                ]);

                                $tipoEvento = 'exit';
                                $isOccupied = false;

                                // === Verificar si hay una nueva clase en la ventana de tiempo ===
                                foreach ($schedules as $schedule) {
                                    if ($schedule->id === $openSession->id) continue;

                                    $dateOnly  = Carbon::parse($schedule->date)->toDateString();
                                    $startHour = Carbon::parse($dateOnly . ' ' . $schedule->start_time, 'America/Bogota')->subMinutes(20);
                                    $endHour   = Carbon::parse($dateOnly . ' ' . $schedule->end_time,   'America/Bogota')->addMinutes(20);

                                    if ($isPermittedFn($schedule) && $now->between($startHour, $endHour)) {
                                        // Abrir nueva sesión automáticamente
                                        $schedule->open_by = $userId;
                                        $schedule->updated_at = $now;
                                        $schedule->save();

                                        \App\Models\Event::create([
                                            'user_id'    => $userId,
                                            'device_id'  => 1,
                                            'ambient_id' => $ambientId,
                                            'event_type' => 'entry',
                                            'created_at' => $now,
                                            'updated_at' => $now,
                                        ]);

                                        $tipoEvento = 'entry';
                                        $isOccupied = true;
                                        $activeSchedule = $schedule;
                                        $activeScheduleDetails = $buildDetails($schedule);
                                        break;
                                    }
                                }
                            }

                        } else {
                            // === PASO 2: Sin sesión abierta — buscar clase en ventana de tiempo ===
                            foreach ($schedules as $schedule) {
                                $dateOnly  = Carbon::parse($schedule->date)->toDateString();
                                $startHour = Carbon::parse($dateOnly . ' ' . $schedule->start_time, 'America/Bogota')->subMinutes(20);
                                $endHour   = Carbon::parse($dateOnly . ' ' . $schedule->end_time,   'America/Bogota')->addMinutes(20);

                                if ($now->between($startHour, $endHour) && $isPermittedFn($schedule)) {
                                    $hasActiveClass = true;
                                    $activeSchedule = $schedule;
                                    $activeScheduleDetails = $buildDetails($schedule);
                                    break;
                                }
                            }

                            if (!$hasActiveClass) {
                                return response()->json([
                                    'success' => false,
                                    'code'    => 'NO_CLASS',
                                    'message' => "Acceso denegado: El docente no tiene clase programada en este ambiente a esta hora.",
                                    'data'    => [
                                        'id'          => $userId,
                                        'nombre'      => $userName,
                                        'distancia'   => $decoded['distancia'] ?? null,
                                        'tipo_evento' => null,
                                        'hasClass'    => false,
                                        'ambient_name' => "Ambiente {$ambientId}",
                                    ]
                                ], 403);
                            }

                            // Solo puede ser entrada nueva (open_by == null en esta rama)
                            $activeSchedule->open_by = $userId;
                            $activeSchedule->updated_at = $now;
                            $activeSchedule->save();

                            \App\Models\Event::create([
                                'user_id'    => $userId,
                                'device_id'  => 1,
                                'ambient_id' => $ambientId,
                                'event_type' => 'entry',
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);

                            $tipoEvento = 'entry';
                            $isOccupied = true;
                        }

                        $decoded['tipo_evento'] = $tipoEvento;

                        // Si tiene clase, agregamos los detalles a la respuesta
                        $decoded['hasClass'] = true;
                        $decoded['message'] = $activeScheduleDetails['full_message'] ?? "Bienvenido. Tienes clase asignada.";
                        $decoded['schedule_data'] = $activeScheduleDetails;

                        // Notificamos a Cronode de forma síncrona para que se actualice al instante sin depender de la cola
                        try {
                            \App\Jobs\NotifyCronodeOccupied::dispatchSync($ambientId, $isOccupied);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error("Error avisando a CRONODE: " . $e->getMessage());
                        }

                    } catch (\Exception $e) {
                        return response()->json([
                            'success' => false,
                            'code' => 'ERROR',
                            'message' => 'Excepción de base de datos local al validar el horario.',
                            'data' => null
                        ], 500);
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
