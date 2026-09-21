<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TenantController;
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
});
