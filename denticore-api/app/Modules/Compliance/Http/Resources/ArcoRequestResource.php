<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Solicitud ARCO (CUS-63, CUS-64; RF-182, RF-183): tipo, solicitante, canal, plazo y estado. La
 * respuesta y el fundamento se ven cuando la solicitud está resuelta (RF-183).
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
            'identity_verification' => $this->identity_verification,
            'channel' => $this->channel,
            'description' => $this->description,
            'status' => $this->status,
            'received_at' => $this->received_at,
            'due_date' => $this->due_date->toDateString(),
            'resolved_at' => $this->resolved_at,
            'within_deadline' => $this->within_deadline,
            'response' => $this->response,
            'denial_reason' => $this->denial_reason,
            'files_count' => $this->whenCounted('files'),
        ];
    }
}
