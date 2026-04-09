<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Carbon\Carbon;
use App\Models\Group;
use App\Models\AmbientSchedule;
use App\Models\Attendance;

class AttendanceReportExport implements FromArray, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    protected Group $group;
    protected string $startDate;
    protected string $endDate;

    /** Filas ya procesadas por buildData() */
    protected array $rows = [];

    /** Columnas de fecha (encabezados dinámicos) */
    protected array $dateColumns = [];

    public function __construct(Group $group, string $startDate, string $endDate)
    {
        $this->group     = $group;
        $this->startDate = $startDate;
        $this->endDate   = $endDate;
        $this->buildData();
    }

    /**
     * Construye las filas de la hoja de cálculo.
     *
     * Lógica de horas ausentes:
     *   - Tardanza  = max(0, registered_at(entry) - start_time)
     *   - Salida temprana = max(0, end_time - registered_at(exit))
     *   - Total horas ausentes = (tardanza_min + salida_temprana_min) / 60  (redondeado a 2 decimales)
     *   - Si no hay entry en absoluto → se cuentan las horas totales de la clase como ausentes.
     *   - 0 = asistió en tiempo completo.
     */
    protected function buildData(): void
    {
        // Obtener todas las sesiones del grupo en el rango de fechas
        $schedules = AmbientSchedule::where('codeTab', $this->group->code_tab)
            ->whereBetween('date', [$this->startDate, $this->endDate])
            ->whereNotNull('open_by')   // solo sesiones que fueron abiertas
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return;
        }

        // Columnas dinámicas: una por cada sesión (fecha + hora inicio)
        foreach ($schedules as $schedule) {
            $label = Carbon::parse($schedule->date)->format('d/m') . ' ' . Carbon::parse($schedule->start_time)->format('H:i');
            $this->dateColumns[$schedule->id] = $label;
        }

        // Estudiantes del grupo
        $students = $this->group->users()->where('role_id', 3)->orderBy('fullname')->get();

        foreach ($students as $student) {
            $row = [$student->fullname];

            foreach ($schedules as $schedule) {
                $entry = Attendance::where('user_id', $student->id)
                    ->where('ambient_schedule_id', $schedule->id)
                    ->where('event_type', 'entry')
                    ->orderBy('registered_at')
                    ->first();

                $exit = Attendance::where('user_id', $student->id)
                    ->where('ambient_schedule_id', $schedule->id)
                    ->where('event_type', 'exit')
                    ->orderBy('registered_at', 'desc')
                    ->first();

                $classDate    = Carbon::parse($schedule->date)->toDateString();
                $classStart   = Carbon::parse($classDate . ' ' . $schedule->start_time, 'America/Bogota');
                $classEnd     = Carbon::parse($classDate . ' ' . $schedule->end_time,   'America/Bogota');
                $classDurMin  = $classStart->diffInMinutes($classEnd);

                if (!$entry) {
                    // Falta total: todas las horas de la clase
                    $horasFaltadas = round($classDurMin / 60, 2);
                } else {
                    $tardanzaMin = max(0, Carbon::parse($entry->registered_at)->diffInMinutes($classStart, false) * -1);

                    $salidaTempranaMin = 0;
                    if ($exit) {
                        $salidaTempranaMin = max(0, Carbon::parse($exit->registered_at)->diffInMinutes($classEnd, false) * -1);
                    }

                    $totalAusenteMin = $tardanzaMin + $salidaTempranaMin;
                    $horasFaltadas   = round($totalAusenteMin / 60, 2);
                }

                $row[] = $horasFaltadas;
            }

            $this->rows[] = $row;
        }
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return array_merge(['Nombre'], array_values($this->dateColumns));
    }

    public function title(): string
    {
        return 'Asistencia ' . $this->group->code_tab;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Fila de encabezados en negrita y fondo azul oscuro
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '2563EB'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}
