<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AmbientAssignmentController;
use App\Http\Controllers\AmbientSettingController;
use App\Http\Controllers\FaceRecognitionController;
use App\Http\Controllers\ScheduleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;


Route::post('recognize', [FaceRecognitionController::class, 'process'])
    ->middleware('throttle:20,1')
    ->name('api.recognize');

Route::get('test', fn() => response()->json(['message' => 'testing']))->middleware('auth:sanctum');

Route::post('test/doc', function (Request $request) {
    $cedula = $request->document;
    $res = Http::withHeaders([
        'x-api-key' => config('app.api.key')
    ])->get(config('app.api.url') . 'api/v1/users/instructors/activeInstructors');
    $docentes = $res->json();
    $doce = array_values(array_filter($docentes['data'], fn($u) => $u['document'] == $cedula));
    if (count($doce) < 1) {
        return response()->json(['msg' => 'No hay docentes registrados con este numero de identidad'], 404);
    }
    return response()->json($doce[0]);
});

// prefijo

Route::prefix('face')->group(function () {

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::post('/2fa/verify', [AuthController::class, 'verifyTwoFactor'])
        ->middleware('throttle:5,1');

    //ambientes
    //ambientes
    Route::get('/ambients', [AmbientAssignmentController::class, 'ambients']);


    //rutas protegidas

    Route::middleware('auth:sanctum')->group(function () {

        //logout
        Route::post('/logout', [AuthController::class, 'logout']);

        //2fa
        Route::post('/2fa/enable', [AuthController::class, 'enableTwoFactor']);
        Route::post('/2fa/disable', [AuthController::class, 'disableTwoFactor']);

        //endpoint para tomas y kevin
        Route::post('/user', [UserController::class, 'store'])->name('user.store')->middleware('docent');


        // rutas admniistrativas
        Route::middleware('admin')->group(function () {
            // configuraciones
            Route::post('/ambient-settings', [AmbientSettingController::class, 'setCoordinates']);


            // buscar el instructor
            Route::post('/search/docent', [UserController::class, 'search'])
            ->name('search.docent');

            // guardar instructor
            Route::post('/docent', [UserController::class, 'store'])
                ->name('docent.store');

            // asignar permiso a clase/horario
            Route::post('/schedules/{id}/permission', [ScheduleController::class, 'assignPermission'])
                ->name('schedules.permission');

            // listar usuarios del sistema
            Route::get('/users', [UserController::class, 'index'])
                ->name('users.index');
            });

            
        //validar usuario
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // horarios y filtrados (accesibles para admin y docentes con logica interna)
        Route::get('/schedules/export', [ScheduleController::class, 'export']);
        Route::get('/schedules', [ScheduleController::class, 'index']);
        Route::get('/schedules/{id}', [ScheduleController::class, 'show']);
    });
});
