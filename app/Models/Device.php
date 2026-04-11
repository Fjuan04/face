<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $fillable = [
        'ambient_id',
        'name',
        'ip_address',
        'status',
    ];
}
