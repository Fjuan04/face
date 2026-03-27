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
        'break_time'
    ];

    protected $casts = [
        'break_time' => 'boolean',
        'date' => 'date',
    ];
}
