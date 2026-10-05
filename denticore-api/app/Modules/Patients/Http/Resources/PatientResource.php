<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentGate;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Ficha del paciente (SDD §4.5, `PatientResource`): documento, teléfono, dirección y número de HC
 * salen descifrados (el cifrado es en reposo); los índices ciegos y los ids internos nunca se
 * exponen.
 *
 * @mixin Patient
 */
class PatientResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'clinical_record_number' => $this->clinical_record_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'birth_date' => $this->birth_date->toDateString(),
            'age_years' => $this->ageYears(),
            'is_minor' => $this->isMinorOn(),
            'sex' => $this->sex,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'archive_status' => $this->archive_status,
            // RN-10, RN-15 (SDD §5.10): consentimiento vigente que habilita al paciente y si usa
            // una versión anterior de la plantilla.
            /** @var bool */
            'has_current_consent' => app(ConsentGate::class)->hasCurrent($this->resource),
            /** @var bool */
            'consent_outdated' => app(ConsentGate::class)->outdated($this->resource),
            'medical_history' => $this->medical_history,
            // RF-064, RNF-149: aviso permanente de alergias en la ficha, la atención y el plan.
            /** @var list<string> */
            'allergies' => $this->medical_history['alergias'] ?? [],
            'user_uuid' => $this->whenLoaded('user', fn () => $this->user?->uuid),
            'created_at' => $this->created_at,
        ];
    }
}
