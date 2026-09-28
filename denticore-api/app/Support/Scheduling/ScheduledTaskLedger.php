<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Ejecución de tareas programadas desde su última ejecución exitosa (SDD §1.9, RNF-087):
 * la tarea procesa la ventana (marca anterior, ahora]; solo si termina bien se avanza la
 * marca. Una caída no pierde efectos (la ventana siguiente incluye lo pendiente) ni los
 * duplica (lo ya procesado queda antes de la marca).
 */
class ScheduledTaskLedger
{
    /**
     * @param  Closure(?CarbonImmutable $since, CarbonImmutable $until): void  $task
     */
    public function run(string $name, ?int $tenantId, Closure $task): void
    {
        DB::transaction(function () use ($name, $tenantId, $task): void {
            $run = ScheduledTaskRun::query()
                ->where('task', $name)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            $until = CarbonImmutable::now();

            $task($run?->watermark, $until);

            ScheduledTaskRun::query()->updateOrCreate(
                ['task' => $name, 'tenant_id' => $tenantId],
                ['last_success_at' => $until, 'watermark' => $until],
            );
        });
    }
}
