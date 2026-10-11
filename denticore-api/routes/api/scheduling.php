<?php

use App\Modules\Scheduling\Http\Controllers\AppointmentTypeController;
use App\Modules\Scheduling\Http\Controllers\ScheduleBlockController;
use App\Modules\Scheduling\Http\Controllers\WorkScheduleController;
use Illuminate\Support\Facades\Route;

// M06 — Agenda y notificaciones (SDD §4.3.8). STAFF (SDD §4.2). Las rutas llegan con sus tareas:
// horario, bloqueos y tipos (TASK-065), disponibilidad (TASK-066), citas (TASK-067, TASK-069) y
// bandeja in-app (TASK-070).

Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api', 'tenant', 'tenant.writable', 'throttle:tenant'])->group(function () {
    // CUS-44: horario laboral (RF-142, RF-144). El personal lo consulta; el Administrador lo cambia.
    Route::get('/dentists/{dentist}/work-schedules', [WorkScheduleController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::put('/dentists/{dentist}/work-schedules', [WorkScheduleController::class, 'replace'])
        ->middleware(['role:clinic_admin', 'idempotent']);

    // CUS-44: bloqueos (RF-143, RF-144). El odontólogo solo los de su agenda (ScheduleBlockPolicy).
    Route::get('/schedule-blocks', [ScheduleBlockController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::middleware('role:clinic_admin,dentist')->group(function () {
        Route::post('/schedule-blocks/impact', [ScheduleBlockController::class, 'impact']);
        Route::post('/schedule-blocks', [ScheduleBlockController::class, 'store'])->middleware('idempotent');
        Route::delete('/schedule-blocks/{block}', [ScheduleBlockController::class, 'destroy']);
    });

    // CUS-45: tipos de cita (RF-145). El personal los consulta; solo el Administrador los gestiona.
    Route::get('/appointment-types', [AppointmentTypeController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');
    Route::middleware('role:clinic_admin')->group(function () {
        Route::post('/appointment-types', [AppointmentTypeController::class, 'store']);
        Route::patch('/appointment-types/{type}', [AppointmentTypeController::class, 'update']);
    });
});
