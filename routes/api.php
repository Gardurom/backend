<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampusController;
use App\Http\Controllers\Api\SchoolGroupController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')
    ->group(function (): void {
        Route::post(
            '/login',
            [AuthController::class, 'login']
        )->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')
            ->group(function (): void {
                Route::get(
                    '/me',
                    [AuthController::class, 'me']
                );

                Route::post(
                    '/logout',
                    [AuthController::class, 'logout']
                );
            });
    });

Route::middleware('auth:sanctum')
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

Route::middleware('auth:sanctum')
    ->prefix('students')
    ->group(function (): void {
        Route::get(
            '/',
            [StudentController::class, 'index']
        )->middleware([
            'campus',
            'permission:students.view',
        ]);

        Route::post(
            '/',
            [StudentController::class, 'store']
        )->middleware([
            'campus',
            'permission:students.create',
        ]);

        Route::get(
            '/{student}',
            [StudentController::class, 'show']
        )->middleware('permission:students.view');

        Route::put(
            '/{student}',
            [StudentController::class, 'update']
        )->middleware('permission:students.update');

        Route::patch(
            '/{student}',
            [StudentController::class, 'update']
        )->middleware('permission:students.update');

        Route::delete(
            '/{student}',
            [StudentController::class, 'destroy']
        )->middleware('permission:students.delete');
    });

Route::middleware([
    'auth:sanctum',
    'campus',
])
    ->prefix('groups')
    ->group(function (): void {
        Route::get(
            '/',
            [SchoolGroupController::class, 'index']
        )->middleware('permission:groups.view');

        Route::post(
            '/',
            [SchoolGroupController::class, 'store']
        )->middleware('permission:groups.create');

        Route::get(
            '/{schoolGroup}',
            [SchoolGroupController::class, 'show']
        )->middleware('permission:groups.view');

        Route::put(
            '/{schoolGroup}',
            [SchoolGroupController::class, 'update']
        )->middleware('permission:groups.update');

        Route::patch(
            '/{schoolGroup}',
            [SchoolGroupController::class, 'update']
        )->middleware('permission:groups.update');

        Route::delete(
            '/{schoolGroup}',
            [SchoolGroupController::class, 'destroy']
        )->middleware('permission:groups.delete');
    });

Route::middleware([
    'auth:sanctum',
    'campus',
])
    ->prefix('teachers')
    ->group(function (): void {
        Route::get(
            '/',
            [TeacherController::class, 'index']
        )->middleware('permission:teachers.view');

        Route::post(
            '/',
            [TeacherController::class, 'store']
        )->middleware('permission:teachers.create');

        Route::get(
            '/{teacher}',
            [TeacherController::class, 'show']
        )->middleware('permission:teachers.view');

        Route::put(
            '/{teacher}',
            [TeacherController::class, 'update']
        )->middleware('permission:teachers.update');

        Route::patch(
            '/{teacher}',
            [TeacherController::class, 'update']
        )->middleware('permission:teachers.update');

        Route::delete(
            '/{teacher}',
            [TeacherController::class, 'destroy']
        )->middleware('permission:teachers.delete');
    });