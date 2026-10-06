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
