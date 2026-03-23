<?php

namespace App\Http\Controllers;

use App\Models\Ambient_assignment;
use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AmbientAssignmentController extends Controller
{


    public function ambients()
    {
        $res = Http::withHeaders([
            'x-api-key' => config('app.api.key')
        ])->get(config('app.api.url') . 'api/v1/ambients');

        if ($res->successful()) {
            // dispositivos asignados
            $devices = Device::pluck('ambient_id');

            // filtramos solo los ambientes que están asignados a un dispositivo
            $ambientes_asignados = collect($res->json('data'))
                ->filter(fn($a) => $devices->contains($a['id']))
                ->values();

            // Cargas las coordenadas que existan en tu tabla local
            $settings = \App\Models\AmbientSetting::whereIn('ambient_id', $ambientes_asignados->pluck('id'))->get();

            // Mapeo iterando cada ambiente para cruzarle "x" e "y" si están en $settings
            $data = $ambientes_asignados->map(function ($amb) use ($settings) {
                $setting = $settings->firstWhere('ambient_id',  $amb['id']);

                if ($setting) {
                    $amb['x'] = (float) $setting->x_coordinate;
                    $amb['y'] = (float) $setting->y_coordinate;
                }

                return $amb;
            })->toArray();

            // Ordenamiento por ID
            usort($data, function ($a, $b) {
                return $a['id'] <=> $b['id'];
            });

            return response()->json($data);
        }

        return response()->json(['message' => 'No se encontraron ambientes'], 404);
    }


    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $ambients = Ambient_assignment::all();
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Ambient_assignment $ambient_assignment)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Ambient_assignment $ambient_assignment)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Ambient_assignment $ambient_assignment)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Ambient_assignment $ambient_assignment)
    {
        //
    }
}
