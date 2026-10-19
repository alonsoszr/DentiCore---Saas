<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas (SDD §1.9)
|--------------------------------------------------------------------------
|
| Un solo scheduler activo (`onOneServer`). Cada módulo agrega aquí sus tareas; las de
| clínica iteran las clínicas activas o suspendidas con TenantContext::run y registran
| su avance en `scheduled_task_runs` (ScheduledTaskLedger).
|
*/

Schedule::command('outbox:prune')->hourly()->onOneServer()->withoutOverlapping();
Schedule::command('idempotency:prune')->hourly()->onOneServer()->withoutOverlapping();

// Verificación diaria de las cadenas de hashes e invariantes (SDD §1.9, RNF-089).
Schedule::command('integrity:verify')->dailyAt('04:00')->onOneServer()->withoutOverlapping();

// Particiones anuales con dos años de anticipación (supuesto S-10, DI-17).
Schedule::command('partitions:ensure')->daily()->onOneServer()->withoutOverlapping();

// Representaciones de pacientes que cumplen 18 años (SDD §1.9, RF-061): a las 00:05 de cada
// clínica. Corre cada hora en el minuto 5 para cubrir cualquier zona horaria; es idempotente.
Schedule::command('representations:end-at-majority')->hourlyAt(5)->onOneServer()->withoutOverlapping();

// Cierre de las atenciones y los odontogramas iniciales pendientes (SDD §1.9; CUS-27, RF-096): a
// las 23:59 de cada clínica. Corre cada 5 minutos para cubrir cualquier zona horaria; es idempotente.
Schedule::command('attentions:auto-close')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Vencimiento de los presupuestos emitidos (SDD §1.9, §5.4.4; CUS-38, RF-124): a las 23:59 de cada
// clínica, a lo más 5 minutos después (RNF-020). Corre cada 5 minutos; es idempotente.
Schedule::command('budgets:expire')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Inasistencias (SDD §1.9, §5.7; CUS-51, RN-51): citas sin check-in 30 minutos después de su fin, a
// lo más 5 minutos más tarde (RNF-020). Corre cada 5 minutos; es idempotente.
Schedule::command('appointments:mark-no-shows')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Recordatorios de cita 24 h antes (SDD §1.9; CUS-52, RF-156, RN-52): ventana de 30 minutos, cada 5
// minutos, con clave de deduplicación por cita.
Schedule::command('appointments:send-reminders')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

// Vencimiento de las predicciones de riesgo de más de 12 meses (SDD §1.9, §2.11; RN-60): a diario
// a las 00:30; es idempotente.
Schedule::command('risk:expire-predictions')->dailyAt('00:30')->onOneServer()->withoutOverlapping();

// Conservación de 90 días de los avisos in-app (SDD §1.9; CUS-53, RF-158).
Schedule::command('notifications:prune-inapp')->dailyAt('03:15')->onOneServer()->withoutOverlapping();

// Aviso del plazo de las solicitudes ARCO (SDD §1.9; CUS-64 FA-2, RF-185): a diario a las 08:00 de
// Lima, zona de las clínicas del Perú; es idempotente (TASK-104, carril Compliance de MS-09).
Schedule::command('arco:deadline-reminders')->dailyAt('08:00')->timezone('America/Lima')->onOneServer()->withoutOverlapping();
