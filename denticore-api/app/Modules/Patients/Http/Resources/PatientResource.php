<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Patient;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Patient
 *
 * document_id y phone salen descifrados (el cifrado es en reposo, §2.2); el índice
 * ciego y los ids internos nunca se exponen.
 */
class PatientResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'document_id' => $this->document_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date->toDateString(),
            'phone' => $this->phone,
            'email' => $this->email,
            /** @var array{alergias: list<string>, enfermedades: list<string>, medicamentos: list<string>, observaciones: string|null}|null */
            'medical_history' => $this->medical_history,
            'user_uuid' => $this->whenLoaded('user', fn () => $this->user?->uuid),
            'created_at' => $this->created_at,
        ];
    }
}
