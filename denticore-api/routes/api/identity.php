<?php

use App\Modules\Identity\Http\Controllers\AuthController;
use App\Modules\Identity\Http\Controllers\InvitationController;
use App\Modules\Identity\Http\Controllers\PasswordResetController;
use App\Modules\Identity\Http\Controllers\PublicClinicController;
use App\Modules\Identity\Http\Controllers\TwoFactorController;
use App\Modules\Identity\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// M02 — Identidad, acceso y seguridad (SDD §4.3.2).

// PUB (SDD §4.2).
Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login', 'tenant.slug:login']);
Route::get('/public/clinics/{slug}', [PublicClinicController::class, 'show'])->middleware(['throttle:public', 'tenant.slug']);

// Recuperación de contraseña (CUS-09) y activación por invitación (CUS-01, CUS-11).
Route::post('/auth/password/forgot', [PasswordResetController::class, 'requestLink'])->middleware(['throttle:login', 'tenant.slug']);
Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])->middleware(['throttle:public', 'tenant.token:restablecimiento', 'throttle:codes']);
Route::get('/auth/invitations/{token}', [InvitationController::class, 'show'])->middleware(['throttle:public', 'tenant.token:invitacion']);
Route::post('/auth/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware(['throttle:public', 'tenant.token:invitacion', 'throttle:codes']);

// AUTH: rutas de la propia cuenta, sin contexto de clínica (SDD §4.2).
Route::middleware(['auth:sanctum', 'token.fresh', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/keepalive', [AuthController::class, 'keepalive'])->middleware('ability:2fa:setup,full');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('ability:2fa:setup,full');

    // Segundo factor (SDD §3.6).
    Route::post('/auth/2fa/verify', [TwoFactorController::class, 'verify'])->middleware(['ability:2fa:pending', 'throttle:codes']);
    Route::post('/auth/2fa/setup', [TwoFactorController::class, 'setup'])->middleware('ability:2fa:setup,full');
    Route::post('/auth/2fa/confirm', [TwoFactorController::class, 'confirm'])->middleware(['ability:2fa:setup,full', 'throttle:codes']);
});

// STAFF (SDD §4.2).
Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant', 'role:clinic_admin'])->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store'])->middleware('idempotent');
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate']);
    Route::post('/users/{user}/reactivate', [UserController::class, 'reactivate']);
    Route::post('/users/{user}/invitation', [UserController::class, 'resendInvitation']);
});
