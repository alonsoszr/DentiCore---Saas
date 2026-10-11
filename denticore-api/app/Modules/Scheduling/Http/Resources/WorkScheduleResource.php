<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Franja del horario laboral (CUS-44; RF-142): día ISO (1 = lunes), horas locales y vigencia.
 *
 * @mixin WorkSchedule
 */
class WorkScheduleResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'weekday' => $this->weekday,
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'valid_from' => $this->valid_from->toDateString(),
            'valid_until' => $this->valid_until?->toDateString(),
        ];
    }
}
