<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * document_id y phone salen descifrados (el cifrado es en reposo, §2.2); el índice
 * ciego y los ids internos nunca se exponen.
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'document_id' => $this->document_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date?->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            'medical_history' => $this->medical_history,
            'user_uuid' => $this->whenLoaded('user', fn () => $this->user?->uuid),
            'created_at' => $this->created_at,
        ];
    }
}
