<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Appointment;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Cita activa afectada por un bloqueo o un cambio de horario (RF-144, RN-82): lo necesario para
 * decidir si se reprograma o se cancela.
 *
 * @mixin Appointment
 */
class AffectedAppointmentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            /** @var 'programada'|'confirmada'|'en_atencion' */
            'status' => $this->status,
            'patient' => ['id' => $this->patient->uuid, 'name' => trim("{$this->patient->first_name} {$this->patient->last_name}")],
            'dentist' => ['id' => $this->dentist->uuid, 'name' => $this->dentist->name],
            'type' => ['id' => $this->type->uuid, 'name' => $this->type->name],
        ];
    }
}
