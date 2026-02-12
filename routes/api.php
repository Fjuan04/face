<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('login', [AuthController::class, 'login']);

// Endpoint API para registrar docentes (protegido)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('teachers', [UserController::class, 'store'])
        ->name('api.teachers.store');
});