<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $users = User::all();
        
        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Vista simple para registrar docentes y tomar la foto
        return view('docentes.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {

        if ($request->route()->getName() === 'user.store') {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'unique:users,id'],
                'fullname' => ['required', 'string', 'max:255'],
                'document' => ['required', 'string', 'max:255'],
                'gender' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'role_id' => ['required', 'exists:roles,id'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:4096'], // 4MB
            ]);
        } else {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'unique:users,id'],
                'document' => ['required', 'string', 'max:255'],
                'gender' => ['required', 'string', 'max:255'],
                'fullname' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
                'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:4096'], // 4MB
            ]);
        }




        // Guardar la foto en un disco PRIVADO (por defecto: storage/app/faces)
        // Usamos el disco 'local' (config/filesystems.php) que no es accesible públicamente por URL.
        $photoPath = $request->file('photo')->store('faces');

        $user = User::create([
            'id' => $validated['id'],
            'document' => $validated['document'] ?? '',
            'gender' => $validated['gender'] ?? 'N/A',
            'fullname' => $validated['fullname'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role_id' => 2,
            'is_active' => true,
            'photo' => $photoPath,
        ]);

        // Respuesta JSON para consumo desde frontend
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Usuario registrado correctamente',
                'user' => $user,
            ], 201);
        }


    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function search(Request $request)
    {
        //
        $documento = $request->document;


        //peticion a cronode
        $res = Http::withHeaders([
            'x-api-key' => config('app.api.key')
        ])->get(config('app.api.url') . 'api/v1/users/instructors/activeInstructors');

        $docentes = $res->json();

        //busqueda del docente por documento y reindexacion de la respuesta
        $docente = array_values(array_filter($docentes['data'],fn($u)=>$u['document'] == $documento ));
        if(count($docente) < 1){
            return response()->json(['message' => 'No se encontraron docentes con este número de identidad'],404);
        }

        return response()->json($docente[0], 200);
    }
}
