<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

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
     * Espera un JSON con:
     * - ip: IP de la ESP32 (opcional en pruebas)
     * - imagen: cadena Base64 (puede venir como Data URL: data:image/jpeg;base64,...)
     */
    public function process(Request $request)
    {
        $archivoTemp = null;
        $ipCliente = $request->ip();
        $ipESP32 = 'Desconocida';

        try {
            $this->logMessage("Inicio del proceso de reconocimiento facial");
            $this->logMessage("IP Cliente: {$ipCliente}");

            $jsonData = $request->getContent();
            $this->logMessage("Cuerpo crudo de la petición: " . (empty($jsonData) ? '[VACÍO]' : substr($jsonData, 0, 500) . (strlen($jsonData) > 500 ? '...' : '')) . " (Longitud: " . strlen($jsonData) . " bytes)");

            $requestData = json_decode($jsonData, true);

            if ($requestData === null) {
                $jsonError = json_last_error_msg();
                $this->logMessage("ERROR: JSON inválido. Error: {$jsonError}. Crudo: " . (empty($jsonData) ? '[VACÍO]' : $jsonData));
                throw new \RuntimeException("Datos JSON inválidos recibidos. Error: {$jsonError}");
            }

            $this->logMessage("Datos JSON decodificados: " . print_r($requestData, true));

            $ipESP32 = $requestData['ip'] ?? 'Desconocida';
            $this->logMessage("IP ESP32: {$ipESP32}");

            if (!isset($requestData['imagen'])) {
                throw new \RuntimeException("No se recibió la clave 'imagen' en los datos JSON.");
            }

            $imagenBase64 = $requestData['imagen'];
            $this->logMessage("Contenido de \$imagenBase64 (primeros 100): " . substr($imagenBase64, 0, 100));
            $this->logMessage("Longitud total de \$imagenBase64: " . strlen($imagenBase64) . " bytes");

            // Normalizar Data URL (data:image/xxx;base64,....) a solo base64 y tipo de archivo
            if (strpos($imagenBase64, 'data:image') === 0) {
                $pattern = '/^data:image\/(\w+);base64,/i';
                if (preg_match($pattern, $imagenBase64, $matches)) {
                    $tipo = strtolower($matches[1]);
                    if (!in_array($tipo, ['jpg', 'jpeg', 'png'])) {
                        throw new \RuntimeException("Tipo de imagen no permitido.");
                    }
                    $imagenBase64 = substr($imagenBase64, strpos($imagenBase64, ',') + 1);
                    $this->logMessage("Prefijo Data URI extraído. Tipo: {$tipo}");
                } else {
                    if (strpos($imagenBase64, ';base64,') !== false) {
                        $parts = explode(';base64,', $imagenBase64);
                        $imagenBase64 = $parts[1];
                        $tipo = 'jpg';
                        $this->logMessage("Advertencia: Prefijo base64 mal formado, se asume JPEG.");
                    } else {
                        throw new \RuntimeException("Formato inválido. El prefijo base64 no es correcto o la cadena no es una Data URI.");
                    }
                }
            } else {
                $tipo = 'jpg';
                $this->logMessage("Advertencia: No se encontró prefijo 'data:image', se asume JPEG y solo datos Base64.");
            }

            $imagenBinaria = base64_decode($imagenBase64);
            if ($imagenBinaria === false) {
                throw new \RuntimeException("Falló decodificación base64. Posiblemente datos corruptos o incompletos.");
            }

            $this->logMessage("Imagen decodificada desde base64 a binario.");

            // Guardar archivo temporal en storage/app/tmp
            $tmpDir = storage_path('app/tmp');
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0775, true);
            }

            $archivoTemp = $tmpDir . DIRECTORY_SEPARATOR . 'temp_' . uniqid() . '.' . $tipo;

            if (file_put_contents($archivoTemp, $imagenBinaria) === false) {
                throw new \RuntimeException("No se pudo escribir el archivo temporal '{$archivoTemp}'. Verifique permisos.");
            }

            $this->logMessage("Archivo temporal creado: {$archivoTemp}");

            // Resolver ruta al ejecutable de Python del venv
            if (PHP_OS_FAMILY === 'Windows') {
                $pythonExecutablePath = base_path('services\\face-recognition\\venv\\Scripts\\python.exe');
            } else {
                $pythonExecutablePath = base_path('services/face-recognition/venv/bin/python');
            }

            $pythonScript = base_path('services/face-recognition/src/reconocer.py');

            if (!file_exists($pythonExecutablePath)) {
                throw new \RuntimeException("El ejecutable de Python no se encuentra en la ruta especificada: {$pythonExecutablePath}");
            }
            $this->logMessage("Ejecutable de Python verificado: {$pythonExecutablePath}");

            if (!file_exists($pythonScript)) {
                throw new \RuntimeException("El script Python 'reconocer.py' no se encuentra en la ruta esperada: {$pythonScript}");
            }
            $this->logMessage("Script Python verificado: {$pythonScript}");

            $pythonExec = escapeshellarg($pythonExecutablePath);
            $comando = "{$pythonExec} " . escapeshellarg($pythonScript) . ' ' . escapeshellarg($archivoTemp) . ' 2>&1';
            $this->logMessage("Comando a ejecutar: {$comando}");

            $salida = shell_exec($comando);
            
            $this->logMessage("Salida cruda del comando Python:\n" . (string) $salida);

            if ($archivoTemp && file_exists($archivoTemp)) {
                unlink($archivoTemp);
                $this->logMessage("Archivo temporal eliminado: {$archivoTemp}");
            }

            if (empty(trim((string) $salida))) {
                $errorMsg = "Script no devolvió nada. Puede ser un problema al ejecutar el comando Python.\nComando: {$comando}";
                $this->logMessage("ERROR: {$errorMsg}");
                throw new \RuntimeException($errorMsg);
            }

            $this->logMessage("Procesando salida del script Python");

            $resultado = json_decode((string) $salida, true);
            if ($resultado === null) {
                $errorMsg = "El script Python no devolvió un JSON válido.\nError JSON: " . json_last_error_msg() . "\nSalida cruda:\n" . $salida . "\nComando: {$comando}";
                $this->logMessage("ERROR: {$errorMsg}");

                $resultado = [
                    'error' => 'El script Python no devolvió un JSON válido',
                    'error_code' => 'INVALID_PYTHON_OUTPUT',
                    'python_output' => $salida,
                    'python_command' => $comando,
                    'ip_esp32' => $ipESP32,
                    'ip_cliente' => $ipCliente,
                ];

                $this->logEnd("Proceso finalizado con errores (salida no JSON)");
                return response()->json($resultado, 500);
            }

            if (isset($resultado['error'])) {
                $resultado['debug_info'] = [
                    'python_command' => $comando,
                    'python_output_raw' => $salida,
                    'ip_esp32' => $ipESP32,
                    'ip_cliente' => $ipCliente,
                ];
            } else {
                $resultado['ip_esp32'] = $ipESP32;
                $resultado['ip_cliente'] = $ipCliente;
            }

            $this->logMessage("Resultado del reconocimiento obtenido");
            $this->logEnd("Proceso completado exitosamente");

            return response()->json($resultado);
        } catch (\Throwable $e) {
            $errorMessage = $e->getMessage();
            $this->logMessage("ERROR: {$errorMessage}");
            $this->logEnd("Proceso finalizado con errores");

            if ($archivoTemp && file_exists($archivoTemp)) {
                unlink($archivoTemp);
                $this->logMessage("Archivo temporal eliminado debido a error: {$archivoTemp}");
            }

            $response = [
                'error' => $errorMessage,
                'error_code' => 'PHP_EXECUTION_ERROR',
                'debug_info' => [
                    'ip_esp32' => $ipESP32,
                    'ip_cliente' => $ipCliente,
                ],
            ];

            return response()->json($response, 500);
        }
    }

    /**
     * Ruta y archivo de log específico para este proceso.
     */
    private function getLogFilePath(): string
    {
        $dir = storage_path('logs/face');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'procesar_' . date('Y-m-d') . '.log';
    }

    private function logMessage(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s.v');
        $entry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->getLogFilePath(), $entry, FILE_APPEND);
    }

    private function logEnd(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s.v');
        $entry = "[{$timestamp}] {$message}\n---- PROCESO FINALIZADO ----\n\n";
        file_put_contents($this->getLogFilePath(), $entry, FILE_APPEND);
    }
}

