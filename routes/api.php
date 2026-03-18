<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\FaceRecognitionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;

Route::post('recognize', [FaceRecognitionController::class, 'process'])
    ->middleware('throttle:20,1')
    ->name('api.recognize');

Route::get('test', fn()=> response()->json(['message'=>'testing']))->middleware('auth:sanctum');

Route::post('test/doc', function(Request $request){
    $cedula = $request->document;
    $res = Http::withHeaders([
        'x-api-key' => config('app.api.key')
    ])->get(config('app.api.url') . 'api/v1/users/instructors/activeInstructors');
    $docentes = $res->json();
    $doce = array_values(array_filter($docentes['data'],fn($u)=>$u['document'] == $cedula ));
    if(count($doce) < 1){
        return response()->json(['msg' => 'No hay docentes registrados con este numero de identidad'], 404);
    }
    return response()->json($doce[0]);
});

Route::prefix('face')->group(function(){

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    Route::post('/2fa/verify', [AuthController::class, 'verifyTwoFactor'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function(){

        Route::post('/docent', [UserController::class, 'store'])
        ->name('docent.store')
        ->middleware('admin');


        Route::post('/2fa/enable', [AuthController::class, 'enableTwoFactor']);
        Route::post('/2fa/disable', [AuthController::class, 'disableTwoFactor']);

        //registro 1. buscar el docente
        Route::post('/search/docent', [UserController::class, 'search'])
        ->name('search.docent');
        //Endpoint para registrar
        Route::post('docent', [UserController::class, 'store'])
        ->name('docent.store');
        //endpoint para tomas y kevin
        Route::post('/user', [UserController::class, 'store'])->name('user.store')->middleware('docent');

        Route::get('/user', function (Request $request) {
            return $request->user();
        });
    });
});
