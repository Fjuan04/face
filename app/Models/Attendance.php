<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ambient_schedule_id',
        'event_type',
        'registered_at',
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    /**
     * El estudiante que registró asistencia.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * La sesión de clase a la que pertenece este registro.
     */
    public function ambientSchedule()
    {
        return $this->belongsTo(AmbientSchedule::class);
    }
}
