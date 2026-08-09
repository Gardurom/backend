<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware('web')
    ->prefix('auth')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')
            ->group(function (): void {
                Route::get('/me', [AuthController::class, 'me']);
                Route::post('/logout', [AuthController::class, 'logout']);
            });
    });
});