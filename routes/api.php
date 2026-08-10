<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\CampusController;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')
        ->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);

            Route::get(
                '/authorization-check',
                function (Request $request) {
                    return response()->json([
                        'message' => 'Autorización correcta.',
                        'user_id' => $request->user()->id,
                    ]);
                }
            )->middleware('permission:dashboard.view');
        });
});

Route::middleware(['web', 'auth:sanctum'])
    ->prefix('campuses')
    ->group(function (): void {
        Route::get(
            '/',
            [CampusController::class, 'index']
        )->middleware('permission:campuses.view');

        Route::post(
            '/',
            [CampusController::class, 'store']
        )->middleware('permission:campuses.create');

        Route::get(
            '/{campus}',
            [CampusController::class, 'show']
        )->middleware('permission:campuses.view');

        Route::put(
            '/{campus}',
            [CampusController::class, 'update']
        )->middleware('permission:campuses.update');

        Route::patch(
            '/{campus}',
            [CampusController::class, 'update']
        )->middleware('permission:campuses.update');

        Route::delete(
            '/{campus}',
            [CampusController::class, 'destroy']
        )->middleware('permission:campuses.delete');
    });