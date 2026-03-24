<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
use Illuminate\Support\Carbon;
use App\Http\Controllers\FaceRecognitionController;

Route::get('/', function () {
    return view('welcome');
});

// Registro docentes para pruebas de reconocimiento
Route::get('/docentes/registro', [UserController::class, 'create'])
    ->name('docentes.create');

Route::post('/docentes/registro', [UserController::class, 'store'])
    ->name('docentes.store');

// Vista de prueba para tomar foto y enviar a la API de reconocimiento
Route::get('/reconocer/test', [FaceRecognitionController::class, 'testView'])
    ->name('reconocer.test');


// ruta prueba admin mdw
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/admin/users', function(){
        return response()->json(['res'=>'admin']);
    });
});

Route::get('hora', function() {
    return Carbon::now()->toDateString();
});
