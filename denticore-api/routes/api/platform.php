<?php

use App\Modules\Platform\Http\Controllers\TenantController;
use Illuminate\Support\Facades\Route;

// M01 — Plataforma y clínicas (SDD §4.3.1). PLAT: sin contexto de clínica (SDD §4.2).
// Contrato heredado /tenants; TASK-023 lo reemplaza por /platform/tenants.

Route::middleware(['auth:sanctum', 'throttle:api', 'role:super_admin'])->group(function () {
    Route::post('/tenants', [TenantController::class, 'store']);
    Route::get('/tenants', [TenantController::class, 'index']);
});
