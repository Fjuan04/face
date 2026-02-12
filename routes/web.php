<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;
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
