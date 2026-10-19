<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Support\Http\ApiResource;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\BusinessDays;
use App\Support\Time\ClinicClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Solicitud ARCO (CUS-63, CUS-64; RF-182, RF-183): tipo, solicitante, canal, plazo y estado. La
 * bandeja del Oficial agrega el titular y los días hábiles que faltan (CUS-64, paso 2; DD-26); la
 * resolución, la respuesta, el fundamento, la copia de la HC y la corrección vinculada.
 *
 * @mixin ArcoRequest
 */
class ArcoRequestResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'type' => $this->type,
            'requested_by' => $this->requested_by,
            'representative_id' => $this->legalRepresentative?->uuid,
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient->uuid,
                'name' => trim($this->patient->first_name.' '.$this->patient->last_name),
            ]),
            'identity_verification' => $this->identity_verification,
            'channel' => $this->channel,
            'description' => $this->description,
            'status' => $this->status,
            'received_at' => $this->received_at,
            'due_date' => $this->due_date->toDateString(),
            'remaining_business_days' => $this->remainingBusinessDays(),
            'resolved_at' => $this->resolved_at,
            'within_deadline' => $this->within_deadline,
            'response' => $this->response,
            'denial_reason' => $this->denial_reason,
            'assigned_dentist_id' => $this->whenLoaded('assignedDentist', fn () => $this->assignedDentist?->uuid),
            'correction_entry_id' => $this->correction_entry_uuid,
            'response_document_id' => $this->whenLoaded('responseDocument', fn () => $this->responseDocument?->uuid),
            'files_count' => $this->whenCounted('files'),
        ];
    }

    /**
     * Días hábiles hasta la fecha límite en la fecha local de la clínica; null si ya está resuelta.
     */
    private function remainingBusinessDays(): ?int
    {
        if (! in_array($this->status, ArcoRequest::OPEN_STATUSES, true)) {
            return null;
        }

        $today = CarbonImmutable::parse(ClinicClock::for(TenantContext::tenant())->localDate(now()));

        return BusinessDays::remaining($today, $this->due_date);
    }
}
