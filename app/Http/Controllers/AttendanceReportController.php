<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Carbon\Carbon;
use App\Models\Group;
use App\Models\AmbientSchedule;
use App\Models\Attendance;
use App\Exports\AttendanceReportExport;

class AttendanceReportController extends Controller
{
    /**
     * Retorna el reporte de asistencia de un grupo como JSON.
     *
     * GET /api/face/attendance/report?group_id=X&start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
     *
     * Respuesta:
     * {
     *   "group": { "id": 1, "code_tab": "123456", "name": "Análisis y Desarrollo de SW" },
     *   "columns": ["Nombre", "07/04 07:00", "08/04 07:00", ...],
     *   "rows": [
     *     { "nombre": "Juan Pérez",   "sessions": [0, 1, 0] },
     *     { "nombre": "Mateo García", "sessions": [2, 0, 1] }
     *   ]
     * }
     *
     * 0 = asistió puntual y se fue a la hora.
     * N = horas ausentes (tardanza + salida temprana).
     */
    public function report(Request $request)
    {
        $request->validate([
            'group_id'   => 'required|exists:groups,id',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $group     = Group::with('users')->findOrFail($request->group_id);
        $startDate = $request->start_date;
        $endDate   = $request->end_date;

        // Obtener sesiones abiertas del grupo en el rango de fechas
        $schedules = AmbientSchedule::where('codeTab', $group->code_tab)
            ->whereBetween('date', [$startDate, $endDate])
            ->whereNotNull('open_by')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        if ($schedules->isEmpty()) {
            return response()->json([
                'success' => true,
                'group'   => [
                    'id'       => $group->id,
                    'code_tab' => $group->code_tab,
                    'name'     => $group->name,
                ],
                'columns' => ['Nombre'],
                'rows'    => [],
                'message' => 'No hay sesiones abiertas para este grupo en el rango de fechas.'
            ]);
        }

        // Encabezados dinámicos: Nombre + una columna por sesión
        $columns = ['Nombre'];
        foreach ($schedules as $schedule) {
            $columns[] = Carbon::parse($schedule->date)->format('d/m') . ' ' . Carbon::parse($schedule->start_time)->format('H:i');
        }

        // Estudiantes del grupo (role_id = 3 = student)
        $students = $group->users()->where('role_id', 3)->orderBy('fullname')->get();

        $rows = [];

        foreach ($students as $student) {
            $sessions = [];

            foreach ($schedules as $schedule) {
                $classDate  = Carbon::parse($schedule->date)->toDateString();
                $classStart = Carbon::parse($classDate . ' ' . $schedule->start_time, 'America/Bogota');
                $classEnd   = Carbon::parse($classDate . ' ' . $schedule->end_time,   'America/Bogota');
                $classDurMin = $classStart->diffInMinutes($classEnd);

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

                if (!$entry) {
                    // Falta total
                    $horasFaltadas = round($classDurMin / 60, 2);
                } else {
                    // Tardanza: minutos entre inicio de clase y hora de entrada (si entró después del inicio)
                    $tardanzaMin = max(0, Carbon::parse($entry->registered_at)->diffInMinutes($classStart, false) * -1);

                    // Salida temprana: minutos entre hora de salida y fin de clase (si salió antes del fin)
                    $salidaTempranaMin = 0;
                    if ($exit) {
                        $salidaTempranaMin = max(0, Carbon::parse($exit->registered_at)->diffInMinutes($classEnd, false) * -1);
                    }

                    $horasFaltadas = round(($tardanzaMin + $salidaTempranaMin) / 60, 2);
                }

                $sessions[] = $horasFaltadas;
            }

            $rows[] = [
                'nombre'   => $student->fullname,
                'sessions' => $sessions,
            ];
        }

        return response()->json([
            'success' => true,
            'group'   => [
                'id'       => $group->id,
                'code_tab' => $group->code_tab,
                'name'     => $group->name,
            ],
            'columns' => $columns,
            'rows'    => $rows,
        ]);
    }

    /**
     * Descarga el reporte de asistencia como archivo Excel.
     *
     * GET /api/face/attendance/export?group_id=X&start_date=Y&end_date=Z
     */
    public function export(Request $request)
    {
        $request->validate([
            'group_id'   => 'required|exists:groups,id',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $group = Group::findOrFail($request->group_id);

        $filename = 'asistencia_' . $group->code_tab . '_' . $request->start_date . '_al_' . $request->end_date . '.xlsx';

        return Excel::download(
            new AttendanceReportExport($group, $request->start_date, $request->end_date),
            $filename
        );
    }
}
