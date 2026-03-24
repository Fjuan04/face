<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmbientSetting extends Model
{
    //
    protected $fillable = ['ambient_id', 'x_coordinate', 'y_coordinate'];
}
