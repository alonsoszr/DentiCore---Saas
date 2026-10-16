<?php

use App\Modules\Portal\Http\Controllers\PortalConsentController;
use App\Modules\Portal\Http\Controllers\PortalOdontogramController;
use App\Modules\Portal\Http\Controllers\PortalSubjectController;
use Illuminate\Support\Facades\Route;

// M10 — Portal del paciente (SDD §4.3.10). PORT (SDD §4.2): solo las rutas de E1 (Plan §5.8).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant', 'role:patient'])
    ->prefix('portal')
    ->group(function () {
        Route::get('/subjects', [PortalSubjectController::class, 'index']);

        Route::middleware('portal.subject')->group(function () {
            // CUS-24: historial por pieza sin notas clínicas (DI-13).
            Route::get('/patients/{patient}/teeth/{tooth}/history', [PortalOdontogramController::class, 'toothHistory'])
                ->whereNumber('tooth');

            // CUS-17 FA-1: consentimiento de datos con el canal `portal`.
            Route::get('/patients/{patient}/consents', [PortalConsentController::class, 'index']);
            Route::get('/patients/{patient}/consents/preview', [PortalConsentController::class, 'preview']);
            Route::post('/patients/{patient}/consents', [PortalConsentController::class, 'store'])
                ->middleware(['portal.adult', 'idempotent']);
        });
    });
