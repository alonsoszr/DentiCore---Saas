<?php

use App\Modules\Patients\Http\Controllers\PatientController;
use App\Support\Audit\AuditClinicalRecordRead;
use Illuminate\Support\Facades\Route;

// M03 — Pacientes y consentimientos (SDD §4.3.3). STAFF (SDD §4.2).

Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function () {
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/patients', [PatientController::class, 'index']);
        Route::post('/patients', [PatientController::class, 'store']);
    });

    // CUS-21: consulta de la ficha, auditada con clinical_record.viewed (SDD §5.14).
    Route::get('/patients/{patient}', [PatientController::class, 'show'])
        ->middleware(['can:view,patient', AuditClinicalRecordRead::class]);
});
