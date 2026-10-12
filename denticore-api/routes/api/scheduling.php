<?php

use App\Modules\Scheduling\Http\Controllers\AppointmentConfirmationController;
use App\Modules\Scheduling\Http\Controllers\AppointmentController;
use App\Modules\Scheduling\Http\Controllers\AppointmentTypeController;
use App\Modules\Scheduling\Http\Controllers\AvailabilityController;
use App\Modules\Scheduling\Http\Controllers\CheckInController;
use App\Modules\Scheduling\Http\Controllers\NotificationController;
use App\Modules\Scheduling\Http\Controllers\ScheduleBlockController;
use App\Modules\Scheduling\Http\Controllers\WaitingRoomController;
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

    // CUS-46: disponibilidad (RF-146).
    Route::get('/availability', [AvailabilityController::class, 'index'])
        ->middleware('role:clinic_admin,dentist,receptionist');

    // CUS-77: agenda y sala de espera (RF-153). El odontólogo lee todas las agendas.
    Route::middleware('role:clinic_admin,dentist,receptionist')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/{appointment}', [AppointmentController::class, 'show']);
        Route::get('/waiting-room', [WaitingRoomController::class, 'index']);
    });

    // CUS-50, CUS-25: check-in, que abre la atención (RF-151, RF-067).
    Route::post('/appointments/{appointment}/check-in', [CheckInController::class, 'store'])
        ->middleware(['role:clinic_admin,receptionist', 'idempotent']);

    // CUS-47 a CUS-49: reserva, reprogramación, cancelación y confirmación (RF-147, RF-149, RF-150).
    Route::middleware('role:clinic_admin,receptionist')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store'])->middleware('idempotent');
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('idempotent');
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        Route::post('/appointments/{appointment}/confirm', [AppointmentConfirmationController::class, 'store']);
    });
});

// CUS-49: confirmación por el enlace del correo, sin sesión (PUB). El token resuelve la clínica.
Route::post('/public/appointment-confirmations/{token}', [AppointmentConfirmationController::class, 'confirmByLink'])
    ->middleware(['throttle:public', 'tenant.token:confirmacion_cita', 'tenant.writable']);

// CUS-53: bandeja in-app de la propia cuenta (AUTH, sin contexto de clínica; RF-158). Cada usuario
// ve solo sus avisos (NotificationController filtra por destinatario).
Route::middleware(['auth:sanctum', 'token.fresh', '2fa', 'throttle:api'])->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
});
