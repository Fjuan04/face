<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AmbientSetting;

class AmbientSettingController extends Controller
{
    //

    //setCoordinates
    public function setCoordinates(Request $request)
    {
        // 1. Validar que vengan los datos correctos numéricos
        $request->validate([
            'ambient_id' => 'required|integer',
            'x'          => 'required|numeric',
            'y'          => 'required|numeric',
        ]);
        // 2. Actualizar si ya existe ese ambiente, o crearlo si es nuevo
        $setting = AmbientSetting::updateOrCreate(
            ['ambient_id'   => $request->ambient_id],
            [
                'x_coordinate' => $request->x,
                'y_coordinate' => $request->y
            ]
        );
        // 3. Devolver la respuesta al frontend 
        return response()->json([
            'message' => 'Coordenadas guardadas correctamente',
            'data'    => $setting
        ]);
    }
}
