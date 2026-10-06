<?php

use App\Modules\Patients\Http\Controllers\ConsentController;
use App\Modules\Patients\Http\Controllers\InformedConsentController;
use App\Modules\Patients\Http\Controllers\InformedConsentTemplateController;
use App\Modules\Patients\Http\Controllers\LegalRepresentativeController;
use App\Modules\Patients\Http\Controllers\MedicalHistoryController;
use App\Modules\Patients\Http\Controllers\PatientController;
use App\Support\Audit\AuditClinicalRecordRead;
use Illuminate\Support\Facades\Route;

// M03 — Pacientes y consentimientos (SDD §4.3.3). STAFF (SDD §4.2).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/patients', [PatientController::class, 'index']);
        // Antes de /patients/{patient} para que «lookup» no se tome como uuid.
        Route::get('/patients/lookup', [PatientController::class, 'lookup']);
        Route::post('/patients', [PatientController::class, 'store'])->middleware('idempotent');
    });

    // CUS-21: consulta de la ficha, auditada con clinical_record.viewed (SDD §5.14).
    Route::get('/patients/{patient}', [PatientController::class, 'show'])
        ->middleware(['can:view,patient', AuditClinicalRecordRead::class]);
    Route::patch('/patients/{patient}', [PatientController::class, 'update'])
        ->middleware(['role:clinic_admin,receptionist', 'can:updateIdentity,patient']);

    // CUS-14, CUS-21: antecedentes médicos solo con consentimiento vigente (RF-064, RN-10).
    Route::put('/patients/{patient}/medical-history', [MedicalHistoryController::class, 'update'])
        ->middleware(['role:clinic_admin,dentist,receptionist', 'consent:atencion']);

    // CUS-17: consentimiento de datos (RF-065 a RF-067).
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/patients/{patient}/consents/preview', [ConsentController::class, 'preview']);
        Route::post('/patients/{patient}/consents', [ConsentController::class, 'store'])->middleware('idempotent');
        Route::get('/patients/{patient}/consents', [ConsentController::class, 'index']);
        Route::get('/consents/{consent}/certificate', [ConsentController::class, 'certificate']);
    });

    // CUS-16: representantes legales (RF-059 a RF-061).
    Route::get('/patients/{patient}/representatives', [LegalRepresentativeController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::middleware('role:clinic_admin,receptionist')->group(function () {
        Route::post('/patients/{patient}/representatives', [LegalRepresentativeController::class, 'store']);
        Route::post('/patients/{patient}/representatives/{representative}/end', [LegalRepresentativeController::class, 'end']);
    });

    // CUS-82: plantillas de consentimiento informado (RF-072; SDD §4.3.3). STAFF consulta, solo
    // el administrador las gestiona (SDD §4.2).
    Route::get('/informed-consent-templates', [InformedConsentTemplateController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::middleware('role:clinic_admin')->group(function () {
        Route::post('/informed-consent-templates', [InformedConsentTemplateController::class, 'store'])->middleware('idempotent');
        Route::put('/informed-consent-templates/{template}', [InformedConsentTemplateController::class, 'update']);
        Route::post('/informed-consent-templates/{template}/deactivate', [InformedConsentTemplateController::class, 'deactivate']);
    });

    // CUS-83: consentimiento informado de un ítem del plan (RF-073, RF-074, RN-12, RN-76; SDD §4.3.3).
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/plan-items/{item}/informed-consents/preview', [InformedConsentController::class, 'preview']);
        Route::post('/plan-items/{item}/informed-consents', [InformedConsentController::class, 'store'])->middleware('idempotent');
        Route::post('/informed-consents/{informedConsent}/revoke', [InformedConsentController::class, 'revoke']);
    });
});
