<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\UserController;

Route::get('/', function () {
    return view('welcome');
});

// Registro docentes para pruebas de reconocimiento
Route::get('/docentes/registro', [UserController::class, 'create'])
    ->name('docentes.create');

Route::post('/docentes/registro', [UserController::class, 'store'])
    ->name('docentes.store');
