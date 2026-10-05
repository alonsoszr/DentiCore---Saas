<?php

use App\Modules\Platform\Http\Controllers\ClinicSettingsController;
use App\Modules\Platform\Http\Controllers\SubscriptionPlanController;
use App\Modules\Platform\Http\Controllers\TenantController;
use App\Modules\Platform\Http\Controllers\TenantPlanController;
use App\Modules\Platform\Http\Controllers\TenantStatusController;
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

Route::middleware(['auth:sanctum', 'token.fresh', 'throttle:api', 'role:super_admin'])->prefix('platform')->group(function () {
    Route::get('/plans', [SubscriptionPlanController::class, 'index']);

    Route::get('/tenants', [TenantController::class, 'index']);
    Route::post('/tenants', [TenantController::class, 'store'])->middleware('idempotent');
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::patch('/tenants/{tenant}', [TenantController::class, 'update']);
    Route::post('/tenants/{tenant}/admin-invitation', [TenantController::class, 'resendInvitation']);
    Route::post('/tenants/{tenant}/suspend', [TenantStatusController::class, 'suspend']);
    Route::post('/tenants/{tenant}/reactivate', [TenantStatusController::class, 'reactivate']);
    Route::put('/tenants/{tenant}/plan', [TenantPlanController::class, 'update']);
});

/*
| Parámetros de la clínica (CUS-04), grupo STAFF: solo clinic_admin.
*/
Route::middleware(['auth:sanctum', 'token.fresh', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant', 'role:clinic_admin'])
    ->prefix('clinic')
    ->group(function () {
        Route::get('/settings', [ClinicSettingsController::class, 'show']);
        Route::patch('/settings', [ClinicSettingsController::class, 'update']);
        Route::post('/logo', [ClinicSettingsController::class, 'uploadLogo']);
    });
