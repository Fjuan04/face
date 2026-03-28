<?php

namespace App\Http\Controllers;

use App\Models\AmbientSchedule;
use App\Models\Device;
use App\Models\Event;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ScheduleController extends Controller
{
    /**
     * Listado filtrado de horarios (clases)
     *
     * GET /api/face/schedules?date=2026-03-27&ambient_id=11&user_id=5
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $query = AmbientSchedule::query();

        // RESTRICCIÓN POR ROL:
        // Si no es admin (role_id != 1), solo puede ver sus propias clases
        if ($user->role_id != 1) {
            $query->where('user_id', $user->id);
        } else {
            // Si es admin, puede filtrar por el user_id que quiera si lo envía en la peticion
            if ($request->has('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        }

        // 1. Filtrar por Fecha específica (por defecto HOY)
        $date = $request->input('date', Carbon::now('America/Bogota')->toDateString());
        $query->whereDate('date', $date);

        // 2. Filtrar por Ambiente
        if ($request->has('ambient_id')) {
            $query->where('ambient_id', $request->ambient_id);
        }

        // 3. Filtrar por Estado
        if ($request->has('status')) {
            if ($request->status === 'open') {
                $query->whereNotNull('open_by')->whereNull('closed_by');
            } elseif ($request->status === 'closed') {
                $query->whereNotNull('closed_by');
            } elseif ($request->status === 'pending') {
                $query->whereNull('open_by');
            }
        }

        $schedules = $query->orderBy('start_time', 'asc')->get();
        
        foreach($schedules as &$class){
            //ambiente
            $ambient = Device::where('ambient_id',$class['ambient_id'])->first();
            //Nombre del ambiente
            $class['ambient'] = $ambient->name ;
        }

 
        return response()->json([   
            'success' => true,
            'role' => ($user->role_id == 1) ? 'admin' : 'docente',
            'filters' => [
                'date' => $date,
                'ambient_id' => $request->ambient_id,
                'user_id' => ($user->role_id != 1) ? $user->id : $request->user_id,
                'status' => $request->status
            ],
            'count' => $schedules->count(),
            'data' => $schedules
        ]);
    }

    /**
     * Detalle completo de una clase incluyendo sus eventos (logs) registrados
     *
     * GET /api/face/schedules/{id}
     */
    public function show($id)
    {
        $user = auth()->user();
        $schedule = AmbientSchedule::find($id);
        $schedule['ambient'] = Device::where('ambient_id', $schedule['ambient_id'])->first()->name;
        if (!$schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Horario no encontrado'
            ], 404);
        }

        // RESTRICCIÓN POR ROL:
        // Si es docente y el horario no le pertenece, bloqueamos el acceso
        if ($user->role_id != 1 && $schedule->user_id != $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para ver los detalles de este horario.'
            ], 403);
        }

        // Buscamos los eventos relacionados
        $events = Event::where('user_id', $schedule->user_id)
            ->where('ambient_id', $schedule->ambient_id)
            ->whereDate('created_at', $schedule->date)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'schedule_info' => $schedule,
                'tracking' => [
                    'is_opened' => !is_null($schedule->open_by),
                    'is_closed' => !is_null($schedule->closed_by),
                    'in_break' => ($schedule->break_time == 1 && is_null($schedule->end_break)),
                ],
                'logs' => $events
            ]
        ]);
    }
}
