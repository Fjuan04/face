<?php

namespace App\Http\Controllers;

use App\Models\Ambient_assignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AmbientAssignmentController extends Controller
{

    public function ambients()
    {
        $res = Http::withHeaders([
            'x-api-key' => config('app.api.key')
        ])->get(config('app.api.url') . 'api/v1/ambients');
            
        if($res->successful()){
            // return response()->json($res->json('data'), 200);
            
            $data = $res->json('data');
            usort($data, function($a,$b){
                return $a['id'] <=> $b['id'];
            } );
            return response()->json($data);
        }
        return response()->json(['message' => 'No se encontraron ambientes'],404);
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
