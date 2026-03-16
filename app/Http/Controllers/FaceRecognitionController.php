<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
     * 
     */
    public function process(Request $request)
    {

        if ($request->hasFile('imagen')) {

            try {
                $photo = $request->file('imagen');

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

                // Devolver directamente el JSON decodificado como respuesta limpia
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
    }
}
