<?php

use App\Modules\Portal\Http\Controllers\PortalAppointmentController;
use App\Modules\Portal\Http\Controllers\PortalBudgetController;
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

            // CUS-36, CUS-37: presupuestos y decisión con el canal `portal`.
            Route::get('/patients/{patient}/budgets', [PortalBudgetController::class, 'index']);
            Route::get('/budgets/{budget}', [PortalBudgetController::class, 'show']);
            Route::get('/budgets/{budget}/pdf', [PortalBudgetController::class, 'pdf']);
            Route::post('/budgets/{budget}/decision', [PortalBudgetController::class, 'decide'])
                ->middleware(['portal.adult', 'idempotent']);

            // CUS-47 a CUS-49, CUS-77: citas del paciente con los límites del portal (RN-48, RN-49).
            Route::get('/patients/{patient}/appointments', [PortalAppointmentController::class, 'index']);
            Route::post('/patients/{patient}/appointments', [PortalAppointmentController::class, 'store'])
                ->middleware(['portal.self_booking', 'idempotent']);
            Route::post('/appointments/{appointment}/reschedule', [PortalAppointmentController::class, 'reschedule'])
                ->middleware('idempotent');
            Route::post('/appointments/{appointment}/cancel', [PortalAppointmentController::class, 'cancel']);
            Route::post('/appointments/{appointment}/confirm', [PortalAppointmentController::class, 'confirm']);
        });

        // CUS-46: disponibilidad para el autoagendamiento (RF-146, RF-148).
        Route::get('/availability', [PortalAppointmentController::class, 'availability'])->middleware('portal.self_booking');
    });
