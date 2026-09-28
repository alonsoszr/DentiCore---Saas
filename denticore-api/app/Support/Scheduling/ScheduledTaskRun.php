<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Última ejecución exitosa de una tarea programada (SDD §2.12, RNF-087). tenant_id nulable
 * (tareas de plataforma): excepción de BelongsToTenant de SDD §1.6.3.
 *
 * @property CarbonImmutable $watermark
 */
#[Fillable(['task', 'tenant_id', 'last_success_at', 'watermark'])]
class ScheduledTaskRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_success_at' => 'immutable_datetime',
            'watermark' => 'immutable_datetime',
        ];
    }
}
