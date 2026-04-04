<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AmbientSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'ambient_id',
        'user_id',
        'teacher_name',
        'codeTab',
        'class',
        'start_time',
        'end_time',
        'date',
        'open_by',
        'closed_by',
        'break_time',
        'admin_permission',
        'user_allowed',
        'granted_by'
    ];

    protected $casts = [
        'break_time' => 'boolean',
        'admin_permission' => 'boolean',
        'date' => 'date',
    ];
}
