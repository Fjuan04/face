<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Models\AmbientSchedule;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Log;

class SyncAmbientSchedulesByDate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cronode:sync-schedules-range {startDate} {endDate?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza los horarios de los ambientes desde CRONODE para un rango de fechas específico';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startDateStr = $this->argument('startDate');
        $endDateStr = $this->argument('endDate') ?? $startDateStr;

        try {
            $startDate = Carbon::parse($startDateStr);
            $endDate = Carbon::parse($endDateStr);
        } catch (\Exception $e) {
            $this->error("Formato de fecha inválido. Use YYYY-MM-DD.");
            return Command::FAILURE;
        }

        if ($startDate->gt($endDate)) {
            $this->error("La fecha de inicio no puede ser posterior a la fecha de fin.");
            return Command::FAILURE;
        }

        $this->info("Iniciando sincronización de horarios desde {$startDate->toDateString()} hasta {$endDate->toDateString()}...");
        
        $baseUrl = config('app.api.url');
        $apiKey = config('app.api.key');

        if (!$baseUrl || !$apiKey) {
            $this->error("Faltan variables de entorno para la conexión con CRONODE.");
            return Command::FAILURE;
        }

        $period = CarbonPeriod::create($startDate, $endDate);
        
        try {
            // Obtener ambientes locales
            $deviceAmbientIds = Device::whereNotNull('ambient_id')->pluck('ambient_id')->unique()->toArray();
            
            if (empty($deviceAmbientIds)) {
                $this->info("No hay ambientes asignados a dispositivos locales para sincronizar.");
                return Command::SUCCESS;
            }

            foreach ($period as $date) {
                $targetDate = $date->toDateString();
                $dayOfWeekIso = $date->dayOfWeekIso;
                
                $this->info("\n--- Procesando fecha: {$targetDate} (Día ISO: {$dayOfWeekIso}) ---");
                
                $totalSchedulesSynced = 0;
                $bar = $this->output->createProgressBar(count($deviceAmbientIds));

                foreach ($deviceAmbientIds as $ambientId) {
                    try {
                        $schedRes = Http::withHeaders(['x-api-key' => $apiKey])
                            ->timeout(8)
                            ->get("{$baseUrl}api/v1/ambients/ambientSchedule/{$ambientId}");

                        if ($schedRes->successful()) {
                            
                            // Limpiar registros previos para esta fecha y ambiente
                            AmbientSchedule::where('ambient_id', $ambientId)
                                ->whereDate('date', $targetDate)
                                ->delete();

                            $schedules = $schedRes->json('data.Schedules') ?? [];
                            
                            if (is_array($schedules) && isset($schedules['startDate'])) {
                                $schedules = [$schedules];
                            }

                            foreach ($schedules as $schedule) {
                                if (!is_array($schedule) || !isset($schedule['startDate']) || !isset($schedule['endDate'])) {
                                    continue;
                                }

                                $schedStart = Carbon::parse($schedule['startDate']);
                                $schedEnd = Carbon::parse($schedule['endDate']);

                                // Verificamos si la fecha objetivo está en el rango de validez del horario
                                if ($date->between($schedStart, $schedEnd)) {
                                    $days = $schedule['day'] ?? [];
                                    if (!is_array($days)) $days = [$days];

                                    // Verificamos si aplica para el día de la semana de la fecha objetivo
                                    if (in_array($dayOfWeekIso, $days)) {
                                        
                                        if (isset($schedule['startHour']) && isset($schedule['endHour'])) {
                                            $startHour = substr($schedule['startHour'], 0, 5) . ':00';
                                            $endHour = substr($schedule['endHour'], 0, 5) . ':00';

                                            AmbientSchedule::create([
                                                'ambient_id' => $ambientId,
                                                'user_id' => $schedule['ConstantUserId'] ?? null,
                                                'teacher_name' => $schedule['ConstantUser']['username'] ?? null,
                                                'codeTab' => $schedule['Programation']['Group']['codeTab'] ?? null,
                                                'class' => $schedule['Programation']['Group']['FormationProgram']['name'] ?? null,
                                                'start_time' => $startHour,
                                                'end_time' => $endHour,
                                                'date' => $targetDate,
                                                'break_time' => false,
                                            ]);
                                            
                                            $totalSchedulesSynced++;
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error("Error sincronizando ambiente {$ambientId} para fecha {$targetDate}: " . $e->getMessage());
                    }
                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
                $this->info("Sincronización terminada para {$targetDate}. Horarios guardados: {$totalSchedulesSynced}");
            }

            $this->newLine();
            $this->info("¡Proceso de rango completado!");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Excepción fatal: " . $e->getMessage());
            Log::error("SyncAmbientSchedulesByDate error fatal: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
