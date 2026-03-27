<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotifyCronodeOccupied implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $ambientId;
    public $isOccupied;

    // Intentos máximos en la cola
    public $tries = 5;

    // Retardo agresivo progresivo (10s, 30s, 1 minuto, 5 minutos)
    public $backoff = [10, 30, 60, 300];

    /**
     * Create a new job instance.
     */
    public function __construct($ambientId, $isOccupied = true)
    {
        $this->ambientId = $ambientId;
        $this->isOccupied = $isOccupied;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cronodeBaseUrl = env('API_BASE_URL');
        $cronodeApiKey = env('API_KEY');

        if (!$cronodeBaseUrl || !$cronodeApiKey) {
            Log::warning("Faltan variables de entorno para notificar a CRONODE.");
            return;
        }

        // Hacemos el request con HTTP
        $response = Http::withHeaders([
            'x-api-key' => $cronodeApiKey,
        ])->timeout(10)->put("{$cronodeBaseUrl}api/v1/ambients/isOccupied/{$this->ambientId}", [
            'isOccupied' => $this->isOccupied
        ]);

        // Si la respuesta no fue satisfactoria (502, 500, etc), lanzamos una excepción.
        // Al lanzar la excepción, Laravel captura el error y REPROGRAMA el job (reintento)
        // según lo hayamos dicho en $tries y $backoff.
        if (!$response->successful()) {
            throw new \Exception("CRONODE falló al recibir la ocupación para el ambiente {$this->ambientId}. Status: " . $response->status());
        }
    }
}
