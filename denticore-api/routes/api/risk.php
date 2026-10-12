<?php

use App\Modules\Risk\Http\Controllers\RiskPredictionController;
use App\Modules\Risk\Http\Controllers\RiskVariableSetController;
use Illuminate\Support\Facades\Route;

// M09 — Predicción de riesgo de caries (SDD §4.3.9). STAFF (SDD §4.2). Las rutas llegan con sus
// tareas: variables (TASK-079), predicción (TASK-081) y alertas (TASK-082). La precarga de
// variables y el historial de predicciones (RF-163, RF-169) quedan en MS-15.

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    // CUS-54: variables de riesgo (RF-161, RF-162, RF-164). La recepción registra las
    // sociodemográficas y el odontólogo las clínicas y conductuales; registrar exige la finalidad
    // (a) (RN-10) y la (d) «predicción» (RN-58) y un plan con riesgo (RN-08).
    Route::middleware('role:dentist,receptionist')->group(function () {
        Route::get('/patients/{patient}/risk-variable-sets', [RiskVariableSetController::class, 'index']);
        Route::middleware(['plan.feature:risk', 'consent:atencion', 'consent:prediccion'])->group(function () {
            Route::post('/patients/{patient}/risk-variable-sets', [RiskVariableSetController::class, 'store']);
            Route::patch('/risk-variable-sets/{set}', [RiskVariableSetController::class, 'update']);
        });
    });

    // CUS-55 a CUS-57: el odontólogo calcula el riesgo (la finalidad (d) la verifica el servicio,
    // SDD §5.8.2); CUS-56: CA y OD consultan la predicción, cada uno con sus campos (SDD §3.5). La
    // consulta no exige el plan: el historial sigue legible tras bajar de plan (RF-023).
    Route::post('/patients/{patient}/risk-predictions', [RiskPredictionController::class, 'store'])
        ->middleware(['role:dentist', 'plan.feature:risk', 'idempotent']);
    Route::get('/risk-predictions/{prediction}', [RiskPredictionController::class, 'show'])
        ->middleware('role:clinic_admin,dentist');
});
