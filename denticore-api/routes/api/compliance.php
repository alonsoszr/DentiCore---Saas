<?php

use App\Modules\Compliance\Http\Controllers\ArcoCancellationController;
use App\Modules\Compliance\Http\Controllers\ArcoRequestController;
use App\Modules\Compliance\Http\Controllers\ClinicalRecordCopyController;
use App\Modules\Compliance\Http\Controllers\GeneratedDocumentController;
use Illuminate\Support\Facades\Route;

// M11 — Cumplimiento y auditoría (SDD §4.3.11). STAFF (SDD §4.2). Las demás rutas llegan con sus tareas.

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    // CUS-36, CUS-62: documento generado con su URL firmada (RF-121, RF-180; DI-16).
    Route::get('/documents/{document}', [GeneratedDocumentController::class, 'show'])
        ->middleware('role:clinic_admin,dentist,receptionist');

    // CUS-63: registro de solicitudes ARCO en la clínica (RF-182).
    Route::middleware('role:clinic_admin,receptionist')->group(function () {
        Route::get('/patients/{patient}/arco-requests', [ArcoRequestController::class, 'indexForPatient']);
        Route::post('/patients/{patient}/arco-requests', [ArcoRequestController::class, 'store'])->middleware('idempotent');
    });

    // CUS-64: atención de solicitudes ARCO por el Oficial de Datos Personales (RF-183 a RF-185).
    Route::middleware(['role:clinic_admin', 'data.officer'])->group(function () {
        Route::get('/arco-requests', [ArcoRequestController::class, 'index']);
        Route::get('/arco-requests/{arcoRequest}', [ArcoRequestController::class, 'show']);
        Route::post('/arco-requests/{arcoRequest}/start', [ArcoRequestController::class, 'start']);
        Route::post('/arco-requests/{arcoRequest}/assign-correction', [ArcoRequestController::class, 'assignCorrection']);
        Route::post('/arco-requests/{arcoRequest}/cancellation', [ArcoCancellationController::class, 'store'])->middleware('idempotent');
        Route::post('/arco-requests/{arcoRequest}/resolve', [ArcoRequestController::class, 'resolve']);
    });

    // CUS-62: copia de la historia clínica, también con la clínica suspendida (RF-180, RN-70).
    Route::post('/patients/{patient}/clinical-record-copies', [ClinicalRecordCopyController::class, 'store'])
        ->middleware(['role:clinic_admin,dentist,receptionist', 'tenant.readonly_ok', 'idempotent']);
});
