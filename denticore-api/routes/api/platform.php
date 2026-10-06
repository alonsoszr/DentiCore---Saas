<?php

use App\Modules\Platform\Http\Controllers\SubscriptionPlanController;
use App\Modules\Platform\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| M01 — Plataforma y clínicas (SDD §4.3.1), grupo PLAT: solo super_admin
|--------------------------------------------------------------------------
|
| El contrato heredado POST/GET /tenants (alta con contraseña del administrador) se retiró
| en TASK-023 (DD-22).
|
*/

Route::middleware(['auth:sanctum', 'throttle:api', 'role:super_admin'])->prefix('platform')->group(function () {
    Route::get('/plans', [SubscriptionPlanController::class, 'index']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('idempotent');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
    Route::post('/tenants/{tenant}/admin-invitation', [TenantController::class, 'resendInvitation']);
});
