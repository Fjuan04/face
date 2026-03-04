<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\FaceRecognitionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::post('login', [AuthController::class, 'login']);

// Endpoint API para registrar docentes (protegido)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('teachers', [UserController::class, 'store'])
        ->name('api.teachers.store');
});

// Endpoint API para reconocimiento facial (NO protegido, usado por ESP32/pruebas)
Route::post('recognize', [FaceRecognitionController::class, 'process'])
    ->name('api.recognize');

Route::get('test', fn()=> response()->json(['message'=>'testing']));
Route::post('test/doc', function(Request $request){

    $cedula = $request->document;
    $docentes = Http::withHeaders([
        'x-api-key' => config('app.api.key')
    ])->get(config('app.api.url') . 'api/v1/users/instructors/activeInstructors');

    $doce = array_filter($docentes['data'],fn($u)=>$u['document'] == $cedula );
    if(count($doce) < 1){
        return response()->json(['msg' => 'No hay docentes con esta ID']);
    }

    return response()->json($doce[0]);
});

