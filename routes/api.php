<?php

use App\Http\Controllers\AuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('ping', function () {
    return 'hola companero';
}); 

Route::get('prueba', function (Request $request){
    return 'hola';
})->middleware('auth:sanctum');

Route::get('PERRO', function(){
    return 'hola cerdo';
})->name('login');

Route::post('login', [AuthController::class, 'login']);