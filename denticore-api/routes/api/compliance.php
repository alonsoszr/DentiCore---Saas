<?php

use App\Modules\Compliance\Http\Controllers\GeneratedDocumentController;
use Illuminate\Support\Facades\Route;

// M11 — Cumplimiento y auditoría (SDD §4.3.11). STAFF (SDD §4.2). Las demás rutas llegan con sus tareas.

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    // CUS-36, CUS-62: documento generado con su URL firmada (RF-121, RF-180; DI-16).
    Route::get('/documents/{document}', [GeneratedDocumentController::class, 'show'])
        ->middleware('role:clinic_admin,dentist,receptionist');
});
