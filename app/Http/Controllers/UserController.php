<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:4096'], // 4MB
        ]);

        // Guardar la foto en un disco PRIVADO (por defecto: storage/app/faces)
        // Usamos el disco 'local' (config/filesystems.php) que no es accesible públicamente por URL.
        $photoPath = $request->file('photo')->store('faces');

        $user = User::create([
            'name' => $validated['name'],
            'surname' => $validated['surname'],
            'email' => $validated['email'],
            'password' => $validated['password'], 
            'role_id' => 2, 
            'is_active' => true,
            'photo' => $photoPath,
        ]);

        // Respuesta JSON para consumo desde frontend
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Docente registrado correctamente',
                'user' => $user,
            ], 201);
        }

        // Respuesta para formulario Blade
        return redirect()
            ->back()
            ->with('status', 'Docente registrado correctamente');
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
}
