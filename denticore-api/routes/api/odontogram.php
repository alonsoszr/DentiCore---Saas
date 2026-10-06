<?php

use App\Modules\Odontogram\Http\Controllers\AttentionAddendumController;
use App\Modules\Odontogram\Http\Controllers\AttentionController;
use App\Modules\Odontogram\Http\Controllers\AttentionDiagnosisController;
use App\Modules\Odontogram\Http\Controllers\Cie10Controller;
use App\Modules\Odontogram\Http\Controllers\ClinicalNoteController;
use App\Support\Audit\AuditClinicalRecordRead;
use Illuminate\Support\Facades\Route;

// M04 — Atención, nota clínica y odontograma (SDD §4.3.4). STAFF (SDD §4.2).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    // CUS-21: atenciones del paciente, auditadas con clinical_record.viewed (SDD §5.14).
    Route::get('/patients/{patient}/attentions', [AttentionController::class, 'index'])
        ->middleware(['role:clinic_admin,dentist', AuditClinicalRecordRead::class]);
    Route::get('/attentions/{attention}', [AttentionController::class, 'show'])
        ->middleware('role:clinic_admin,dentist');

    // CUS-25, CUS-26 (RF-082, RF-094).
    Route::post('/patients/{patient}/attentions', [AttentionController::class, 'store'])
        ->middleware(['role:dentist', 'cop', 'idempotent']);
    Route::post('/attentions/{attention}/close', [AttentionController::class, 'close'])
        ->middleware(['role:dentist', 'idempotent']);

    // CUS-80: nota y diagnósticos CIE-10 (RF-084, RF-085, RF-090).
    Route::get('/cie10', [Cie10Controller::class, 'search'])->middleware('role:dentist');
    Route::put('/attentions/{attention}/note', [ClinicalNoteController::class, 'update'])
        ->middleware(['role:dentist', 'cop', 'consent:atencion']);
    Route::post('/attentions/{attention}/diagnoses', [AttentionDiagnosisController::class, 'store'])
        ->middleware(['role:dentist', 'cop', 'consent:atencion']);
    Route::delete('/attentions/{attention}/diagnoses/{diagnosis}', [AttentionDiagnosisController::class, 'destroy'])
        ->middleware('role:dentist')
        ->scopeBindings();

    // CUS-81: adendas a una atención cerrada (RF-097).
    Route::post('/attentions/{attention}/addenda', [AttentionAddendumController::class, 'store'])
        ->middleware(['role:dentist', 'cop']);
});
