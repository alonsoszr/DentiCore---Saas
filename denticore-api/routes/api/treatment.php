<?php

use App\Modules\Treatment\Http\Controllers\ProcedureCatalogController;
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
});
