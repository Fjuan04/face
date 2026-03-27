<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Device;
use App\Models\AmbientSchedule;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class SyncAmbientSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cronode:sync-ambient-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza los horarios de los ambientes desde CRONODE para el día actual';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info("Iniciando sincronización de horarios...");
        
        $baseUrl = config('app.api.url');
        $apiKey = config('app.api.key');

        if (!$baseUrl || !$apiKey) {
            $this->error("Faltan variables de entorno para la conexión con CRONODE.");
            return Command::FAILURE;
        }

        try {
            $res = Http::withHeaders(['x-api-key' => $apiKey])->timeout(10)->get($baseUrl . 'api/v1/ambients');
            
            if (!$res->successful()) {
                $this->error("No se pudo obtener la lista de ambientes.");
                return Command::FAILURE;
            }

            $ambients = $res->json('data') ?? [];
            
            // Solo sincronizamos los ambientes que están en nuestra DB local (asignados a dispositivos)
            $deviceAmbientIds = Device::pluck('ambient_id')->toArray();
            $targetAmbients = array_filter($ambients, function($amb) use ($deviceAmbientIds) {
                return in_array($amb['id'], $deviceAmbientIds);
            });

            if (empty($targetAmbients)) {
                $this->info("No hay ambientes asignados a dispositivos locales para sincronizar.");
                return Command::SUCCESS;
            }

            $todayDate = Carbon::now('America/Bogota')->toDateString();
            $currentDayIso = Carbon::now('America/Bogota')->dayOfWeekIso; // 1 (Lunes) a 7 (Domingo)

            $this->info("Sincronizando horarios para la fecha: {$todayDate}");
            $totalSchedulesSynced = 0;

            $bar = $this->output->createProgressBar(count($targetAmbients));

            foreach ($targetAmbients as $amb) {
                try {
                    $schedRes = Http::withHeaders(['x-api-key' => $apiKey])
                        ->timeout(6)
                        ->get("{$baseUrl}api/v1/ambients/ambientSchedule/{$amb['id']}");

                    if ($schedRes->successful()) {
                        
                        // Eliminamos los horarios de hoy para este ambiente específico
                        // Esto garantiza que no haya duplicados si el script se corre 2 veces
                        AmbientSchedule::where('ambient_id', $amb['id'])
                            ->whereDate('date', $todayDate)
                            ->delete();

                        $schedules = $schedRes->json('data.Schedules') ?? [];
                        
                        // Si viene un solo objeto en vez de array, lo envolvemos
                        if (is_array($schedules) && isset($schedules['startDate'])) {
                            $schedules = [$schedules];
                        }

                        foreach ($schedules as $schedule) {
                            if (!is_array($schedule) || !isset($schedule['startDate']) || !isset($schedule['endDate'])) {
                                continue;
                            }

                            $startDate = Carbon::parse($schedule['startDate']);
                            $endDate = Carbon::parse($schedule['endDate']);
                            $now = Carbon::now('America/Bogota');

                            // Verificamos si la fecha actual está en el rango de fechas
                            if ($now->between($startDate, $endDate)) {
                                $days = $schedule['day'] ?? [];
                                if (!is_array($days)) $days = [$days];

                                // Verificamos si aplica para el día de la semana actual
                                if (in_array($currentDayIso, $days)) {
                                    
                                    if (isset($schedule['startHour']) && isset($schedule['endHour'])) {
                                        $startHour = substr($schedule['startHour'], 0, 5) . ':00';
                                        $endHour = substr($schedule['endHour'], 0, 5) . ':00';

                                        AmbientSchedule::create([
                                            'ambient_id' => $amb['id'],
                                            'user_id' => $schedule['ConstantUserId'] ?? null,
                                            'teacher_name' => $schedule['ConstantUser']['username'] ?? null,
                                            'codeTab' => $schedule['Programation']['Group']['codeTab'] ?? null,
                                            'class' => $schedule['Programation']['Group']['FormationProgram']['name'] ?? null,
                                            'start_time' => $startHour,
                                            'end_time' => $endHour,
                                            'date' => $todayDate,
                                            'break_time' => false,
                                        ]);
                                        
                                        $totalSchedulesSynced++;
                                    }
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Log::error("Error sincronizando ambiente {$amb['id']}: " . $e->getMessage());
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
            $this->info("¡Sincronización completada! Horarios guardados para hoy: {$totalSchedulesSynced}");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Excepción fatal: " . $e->getMessage());
            Log::error("SyncAmbientSchedules error fatal: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
