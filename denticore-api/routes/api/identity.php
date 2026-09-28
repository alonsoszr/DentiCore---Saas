<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// M02 — Identidad, acceso y seguridad (SDD §4.3.2).

Route::post('/auth/login', [AuthController::class, 'login']);

// AUTH: rutas de la propia cuenta, sin contexto de clínica (SDD §4.2).
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

// STAFF (SDD §4.2).
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant', 'role:clinic_admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
});
