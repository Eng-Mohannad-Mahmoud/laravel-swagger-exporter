<?php

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Laraswag\LaravelSwaggerExporter\Controllers\SwaggerController;

if (App::environment('local', 'development')) {
    Route::get('/swagger', [SwaggerController::class, 'index'])->name('swagger.index');
    Route::get('/swagger/{prefix}/{file}', [SwaggerController::class, 'showFromPrefix'])
        ->where('prefix', '.*')->where('file', '[^/]+');;
    Route::get('/swagger/{file}', [SwaggerController::class, 'showFromFile']);
}

Route::get('api/ping', fn () => response()->json(['message' => 'pong'], 200));
Route::middleware('auth:sanctum')->get('api/ping-auth', fn () => response()->json(['message' => 'pong'], 200));