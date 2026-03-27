<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'user_id',
        'device_id',
        'ambient_id',
        'event_type',
    ];
}
