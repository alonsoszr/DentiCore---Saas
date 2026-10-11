<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Bloqueo de agenda (CUS-44; RF-143): odontólogo (null = toda la clínica), intervalo, tipo y motivo.
 *
 * @mixin ScheduleBlock
 */
class ScheduleBlockResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'dentist' => $this->dentist === null ? null : ['id' => $this->dentist->uuid, 'name' => $this->dentist->name],
            'starts_at' => $this->startsAt()->toIso8601String(),
            'ends_at' => $this->endsAt()->toIso8601String(),
            /** @var 'vacaciones'|'capacitacion'|'feriado'|'cierre'|'otro' */
            'kind' => $this->kind,
            'reason' => $this->reason,
            'created_by' => ['id' => $this->creator->uuid, 'name' => $this->creator->name],
        ];
    }
}
