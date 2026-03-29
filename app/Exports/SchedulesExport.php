<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Illuminate\Support\Facades\DB;

class SchedulesExport implements FromQuery, WithHeadings, WithMapping
{
    protected $startDate;
    protected $endDate;
    protected $userId;
    protected $isAdmin;

    public function __construct($startDate, $endDate, $userId, $isAdmin = false)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->userId = $userId;
        $this->isAdmin = $isAdmin;
    }

    public function query()
    {
        $query = DB::table('ambient_schedules')
            ->join('devices', 'ambient_schedules.ambient_id', '=', 'devices.ambient_id')
            ->select(
                'devices.name as ambient_name',
                'ambient_schedules.teacher_name',
                'ambient_schedules.codeTab',
                'ambient_schedules.class',
                'ambient_schedules.date',
                'ambient_schedules.start_time',
                'ambient_schedules.end_time',
                'ambient_schedules.break_time',
                'ambient_schedules.start_break',
                'ambient_schedules.end_break',
                'ambient_schedules.user_id'
            )
            ->whereBetween('ambient_schedules.date', [$this->startDate, $this->endDate]);

        if (!$this->isAdmin) {
            $query->where('ambient_schedules.user_id', $this->userId);
        }

        $query->orderBy('ambient_schedules.date', 'asc')
              ->orderBy('ambient_schedules.start_time', 'asc');

        return $query;
    }

    public function headings(): array
    {
        return [
            'Ambiente',
            'Instructor',
            'Ficha',
            'Clase',
            'Fecha',
            'Hora inicio',
            'Hora fin',
            'Descanso'
        ];
    }

    /**
     * @param mixed $row
     *
     * @return array
     */
    public function map($row): array
    {
        $breakDuration = '00:00:00';
        
        if ($row->start_break && $row->end_break) {
            $start = \Carbon\Carbon::parse($row->start_break);
            $end = \Carbon\Carbon::parse($row->end_break);
            $diff = $start->diff($end);
            $breakDuration = $diff->format('%H:%I:%S');
        } elseif ($row->break_time && !$row->end_break) {
            $breakDuration = 'En curso';
        }

        return [
            $row->ambient_name,
            $row->teacher_name,
            $row->codeTab,
            $row->class,
            $row->date,
            $row->start_time,
            $row->end_time,
            $breakDuration,
        ];
    }
}
