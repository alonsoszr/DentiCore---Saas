<?php

use App\Modules\Portal\Http\Controllers\PortalSubjectController;
use Illuminate\Support\Facades\Route;

// M10 — Portal del paciente (SDD §4.3.10). PORT (SDD §4.2): solo las rutas de E1 (Plan §5.8).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant', 'role:patient'])
    ->prefix('portal')
    ->group(function () {
        Route::get('/subjects', [PortalSubjectController::class, 'index']);
    });
