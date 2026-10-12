<?php

namespace App\Modules\Risk\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Risk\Services\RiskVariableValidator;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Registro de variables de riesgo (SDD §2.14.2, §3.5; CUS-54; RF-161, RF-164): las tres secciones,
 * qué falta para predecir (`missing`), quién y cuándo capturó cada parte, el registro que reemplaza
 * y si ya está bloqueado por una predicción. Lo leen el odontólogo y la recepción.
 *
 * @mixin RiskVariableSet
 */
class RiskVariableSetResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        $capturer = fn (?User $user) => $user === null ? null : ['id' => $user->uuid, 'name' => $user->name];

        return [
            /** @var 'menor'|'adulto' */
            'age_group' => $this->age_group,
            'age_years' => $this->age_years,
            /** @var array{cpod?: int|null, ceod?: int|null, plaque_index_pct?: float, active_lesions?: int} */
            'clinical' => (object) $this->clinical,
            /** @var array{sugar_between_meals?: string, fluoride_toothpaste?: bool, brushing_per_day?: string, dental_visits_per_year?: string} */
            'behavioral' => (object) $this->behavioral,
            /** @var array{respondent?: string, education_level?: string, employment_status?: string, family_structure?: string|null} */
            'sociodemographic' => (object) $this->sociodemographic,
            'is_complete' => $this->is_complete,
            /** @var list<string> */
            'missing' => RiskVariableValidator::missing($this->resource),
            'clinical_captured_by' => $capturer($this->clinicalCapturedBy),
            'clinical_captured_at' => $this->clinical_captured_at?->toIso8601String(),
            'sociodemographic_captured_by' => $capturer($this->sociodemographicCapturedBy),
            'sociodemographic_captured_at' => $this->sociodemographic_captured_at?->toIso8601String(),
            'supersedes_id' => $this->supersedes?->uuid,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
