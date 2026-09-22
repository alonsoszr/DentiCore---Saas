<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// technical_specs.md §5.1 — Autenticación y plataforma

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'ResolveTenant'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::middleware('EnsureRole:super_admin')->group(function () {
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/tenants', [TenantController::class, 'index']);
    });

    // technical_specs.md §5.2 — Usuarios y roles (clínica)
    Route::middleware('EnsureRole:clinic_admin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
    });

    // technical_specs.md §5.3 — Pacientes
    Route::middleware('EnsureRole:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/patients', [PatientController::class, 'index']);
        Route::post('/patients', [PatientController::class, 'store']);
    });
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->middleware('can:view,patient');
});
