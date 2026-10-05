<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\PublicClinicController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// M02 — Identidad, acceso y seguridad (SDD §4.3.2).

// PUB (SDD §4.2).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login', 'tenant.slug:login']);
Route::get('/public/clinics/{slug}', [PublicClinicController::class, 'show'])->middleware(['throttle:public', 'tenant.slug']);

// AUTH: rutas de la propia cuenta, sin contexto de clínica (SDD §4.2).
Route::middleware(['auth:sanctum', 'token.fresh', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/keepalive', [AuthController::class, 'keepalive'])->middleware('ability:2fa:setup,full');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('ability:2fa:setup,full');
});

// STAFF (SDD §4.2).
Route::middleware(['auth:sanctum', 'token.fresh', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant', 'role:clinic_admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
});
