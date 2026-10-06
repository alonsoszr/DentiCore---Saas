<?php

use App\Modules\Odontogram\Http\Controllers\AttentionController;
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
});
