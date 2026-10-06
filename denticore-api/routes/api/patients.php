<?php

use App\Modules\Patients\Http\Controllers\LegalRepresentativeController;
use App\Modules\Patients\Http\Controllers\PatientController;
use App\Support\Audit\AuditClinicalRecordRead;
use Illuminate\Support\Facades\Route;

// M03 — Pacientes y consentimientos (SDD §4.3.3). STAFF (SDD §4.2).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/patients', [PatientController::class, 'index']);
        Route::post('/patients', [PatientController::class, 'store']);
    });

    // CUS-21: consulta de la ficha, auditada con clinical_record.viewed (SDD §5.14).
    Route::get('/patients/{patient}', [PatientController::class, 'show'])
        ->middleware(['can:view,patient', AuditClinicalRecordRead::class]);

    // CUS-16: representantes legales (RF-059 a RF-061).
    Route::get('/patients/{patient}/representatives', [LegalRepresentativeController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::middleware('role:clinic_admin,receptionist')->group(function () {
        Route::post('/patients/{patient}/representatives', [LegalRepresentativeController::class, 'store']);
        Route::post('/patients/{patient}/representatives/{representative}/end', [LegalRepresentativeController::class, 'end']);
    });
});
