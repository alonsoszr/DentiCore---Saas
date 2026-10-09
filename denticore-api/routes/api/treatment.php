<?php

use App\Modules\Treatment\Http\Controllers\BudgetController;
use App\Modules\Treatment\Http\Controllers\BudgetDecisionController;
use App\Modules\Treatment\Http\Controllers\BudgetDocumentController;
use App\Modules\Treatment\Http\Controllers\BudgetIssueController;
use App\Modules\Treatment\Http\Controllers\BudgetLineController;
use App\Modules\Treatment\Http\Controllers\PerformedProcedureController;
use App\Modules\Treatment\Http\Controllers\PlanItemController;
use App\Modules\Treatment\Http\Controllers\ProcedureCatalogController;
use App\Modules\Treatment\Http\Controllers\TreatmentPlanController;
use App\Modules\Treatment\Http\Controllers\UrgentProcedureController;
use Illuminate\Support\Facades\Route;

// M05 — Catálogo, plan de tratamiento, presupuesto y procedimientos (SDD §4.3.5). STAFF (SDD §4.2).
// Las rutas llegan con sus tareas: catálogo (TASK-055), plan (TASK-056), presupuesto (TASK-058,
// TASK-059) y procedimiento realizado (TASK-061).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    // CUS-32: catálogo de procedimientos (RF-107, RF-109). El personal lo consulta; solo el
    // Administrador de Clínica lo modifica.
    Route::get('/procedures', [ProcedureCatalogController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::middleware('role:clinic_admin')->group(function () {
        Route::post('/procedures', [ProcedureCatalogController::class, 'store']);
        Route::patch('/procedures/{procedure}', [ProcedureCatalogController::class, 'update']);
        Route::delete('/procedures/{procedure}', [ProcedureCatalogController::class, 'destroy']);
    });

    // CUS-33: plan de tratamiento (RF-110, RF-111, RF-114, RF-130). El personal consulta los planes
    // con su avance; el odontólogo los elabora y los presenta.
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'index']);
        Route::get('/treatment-plans/{plan}', [TreatmentPlanController::class, 'show']);
    });
    Route::post('/patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'store'])
        ->middleware(['role:dentist', 'cop', 'consent:atencion', 'idempotent']);
    Route::post('/treatment-plans/{plan}/items', [PlanItemController::class, 'store'])
        ->middleware(['role:dentist', 'cop']);
    Route::middleware('role:dentist')->group(function () {
        Route::patch('/treatment-plans/{plan}', [TreatmentPlanController::class, 'update']);
        Route::patch('/plan-items/{item}', [PlanItemController::class, 'update']);
        Route::delete('/plan-items/{item}', [PlanItemController::class, 'destroy']);
        Route::post('/treatment-plans/{plan}/propose', [TreatmentPlanController::class, 'propose']);
        Route::post('/treatment-plans/{plan}/reopen', [TreatmentPlanController::class, 'reopen']);
    });

    // CUS-40: descartar ítems y cancelar planes con motivo (RF-114, RF-129).
    Route::middleware('role:clinic_admin,dentist')->group(function () {
        Route::post('/plan-items/{item}/discard', [PlanItemController::class, 'discard']);
        Route::get('/treatment-plans/{plan}/cancellation-preview', [TreatmentPlanController::class, 'cancellationPreview']);
        Route::post('/treatment-plans/{plan}/cancel', [TreatmentPlanController::class, 'cancel']);
    });

    // CUS-35, CUS-36: borrador, emisión, corrección y PDF del presupuesto (RF-115, RF-116, RF-118 a
    // RF-121). El personal los gestiona; el tope de descuento por rol lo aplica BudgetService (RN-31).
    // `/budgets/{budget}/price-diff` (RF-117) llega en MS-15.
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::post('/treatment-plans/{plan}/budgets', [BudgetController::class, 'store'])->middleware('idempotent');
        Route::get('/patients/{patient}/budgets', [BudgetController::class, 'index']);
        Route::get('/budgets/{budget}', [BudgetController::class, 'show']);
        Route::patch('/budgets/{budget}/lines/{line}', [BudgetLineController::class, 'update'])->scopeBindings();
        Route::delete('/budgets/{budget}', [BudgetController::class, 'destroy']);
        Route::post('/budgets/{budget}/issue', [BudgetIssueController::class, 'store'])->middleware('idempotent');
        Route::post('/budgets/{budget}/corrections', [BudgetController::class, 'correct'])->middleware('idempotent');
        Route::get('/budgets/{budget}/pdf', [BudgetDocumentController::class, 'show']);
        Route::post('/budgets/{budget}/pdf/regenerate', [BudgetDocumentController::class, 'regenerate']);
    });

    // CUS-37: decisión presencial sobre el presupuesto (RF-122, RF-123). La registran la recepción y
    // el Administrador de Clínica; el portal y el enlace con OTP llegan en MS-06.
    Route::post('/budgets/{budget}/decision', [BudgetDecisionController::class, 'store'])
        ->middleware(['role:clinic_admin,receptionist', 'idempotent']);

    // CUS-39: procedimiento realizado y de urgencia (RF-126 a RF-128, RN-38, RN-39, RN-76). El
    // odontólogo lo registra en su atención abierta del paciente.
    Route::middleware(['role:dentist', 'cop', 'consent:atencion', 'idempotent'])->group(function () {
        Route::post('/plan-items/{item}/performed-procedures', [PerformedProcedureController::class, 'store']);
        Route::post('/attentions/{attention}/urgent-procedures', [UrgentProcedureController::class, 'store']);
    });
});
