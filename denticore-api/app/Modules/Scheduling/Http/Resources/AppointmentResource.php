<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Appointment;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Cita (CUS-47 a CUS-51, CUS-77; estados de SRS §5.5.4): paciente, odontólogo, tipo, intervalo,
 * estado, confirmación y cancelación.
 *
 * @mixin Appointment
 */
class AppointmentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'patient' => [
                'id' => $this->patient->uuid,
                'name' => trim("{$this->patient->first_name} {$this->patient->last_name}"),
            ],
            'dentist' => ['id' => $this->dentist->uuid, 'name' => $this->dentist->name],
            'type' => ['id' => $this->type->uuid, 'name' => $this->type->name, 'color' => $this->type->color],
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            /** @var 'programada'|'confirmada'|'en_atencion'|'atendida'|'cancelada'|'inasistencia' */
            'status' => $this->status,
            /** @var 'clinica'|'portal' */
            'origin' => $this->origin,
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            /** @var 'enlace'|'portal'|'telefono'|null */
            'confirmation_channel' => $this->confirmation_channel,
            'reconfirmation_pending' => $this->reconfirmation_pending,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            /** @var 'paciente'|'clinica'|'otro'|null */
            'cancel_reason' => $this->cancel_reason,
            'cancel_detail' => $this->cancel_detail,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
