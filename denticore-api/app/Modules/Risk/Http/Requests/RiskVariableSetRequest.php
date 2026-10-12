<?php

namespace App\Modules\Risk\Http\Requests;

use App\Modules\Patients\Models\Patient;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Risk\Services\RiskVariableSetService;
use App\Modules\Risk\Services\RiskVariableValidator;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Secciones de variables de riesgo (SDD §2.14.2; CUS-54; RF-161, RF-162, DI-20). Cada sección
 * enviada llega completa; los campos obligatorios dependen de la edad del paciente al capturar:
 * CPOD o ceod según la dentición vigente, informante `representante` o `titular` y estructura
 * familiar solo en menores. El rol decide qué secciones puede escribir (RiskVariableSetPolicy).
 */
class RiskVariableSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $set = $this->route('set');

        return $set instanceof RiskVariableSet
            ? Gate::allows('update', [$set, $this->sectionNames()])
            : Gate::allows('create', [RiskVariableSet::class, $this->sectionNames()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $patient = $this->patient();
        $isMinor = $patient->isMinorOn();
        $required = RiskVariableValidator::required($patient->ageYears(), $isMinor);
        $presence = fn (string $section, string $field): string => in_array($field, $required[$section], true) ? 'required' : 'nullable';

        $rules = [
            'clinical' => ['required_without_all:behavioral,sociodemographic', 'array:cpod,ceod,plaque_index_pct,active_lesions'],
            'behavioral' => ['sometimes', 'array:sugar_between_meals,fluoride_toothpaste,brushing_per_day,dental_visits_per_year'],
            'sociodemographic' => ['sometimes', 'array:respondent,education_level,employment_status,family_structure'],
        ];

        if ($this->has('clinical')) {
            $rules += [
                'clinical.cpod' => [$presence('clinical', 'cpod'), 'integer', 'between:0,32'],
                'clinical.ceod' => [$presence('clinical', 'ceod'), 'integer', 'between:0,20'],
                'clinical.plaque_index_pct' => ['required', 'numeric', 'between:0,100', 'decimal:0,1'],
                'clinical.active_lesions' => ['required', 'integer', 'between:0,32'],
            ];
        }

        if ($this->has('behavioral')) {
            $rules += [
                'behavioral.sugar_between_meals' => ['required', 'string', Rule::in(RiskVariableValidator::SUGAR)],
                'behavioral.fluoride_toothpaste' => ['required', 'boolean'],
                'behavioral.brushing_per_day' => ['required', 'string', Rule::in(RiskVariableValidator::BRUSHING)],
                'behavioral.dental_visits_per_year' => ['required', 'string', Rule::in(RiskVariableValidator::VISITS)],
            ];
        }

        if ($this->has('sociodemographic')) {
            $rules += [
                'sociodemographic.respondent' => ['required', Rule::in([$isMinor ? 'representante' : 'titular'])],
                'sociodemographic.education_level' => ['required', Rule::in(RiskVariableValidator::EDUCATION)],
                'sociodemographic.employment_status' => ['required', Rule::in(RiskVariableValidator::EMPLOYMENT)],
                'sociodemographic.family_structure' => $isMinor
                    ? ['required', Rule::in(RiskVariableValidator::FAMILY)]
                    : ['prohibited'],
            ];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'clinical.cpod' => 'CPOD',
            'clinical.ceod' => 'ceod',
            'clinical.plaque_index_pct' => 'índice de placa',
            'clinical.active_lesions' => 'lesiones activas',
            'behavioral.sugar_between_meals' => 'azúcar entre comidas',
            'behavioral.fluoride_toothpaste' => 'pasta fluorada',
            'behavioral.brushing_per_day' => 'cepillado diario',
            'behavioral.dental_visits_per_year' => 'visitas al odontólogo',
            'sociodemographic.respondent' => 'informante',
            'sociodemographic.education_level' => 'nivel educativo',
            'sociodemographic.employment_status' => 'situación laboral',
            'sociodemographic.family_structure' => 'estructura familiar',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sociodemographic.family_structure.prohibited' => 'La estructura familiar solo se registra en menores de edad.',
            'sociodemographic.respondent.in' => 'El informante debe ser el representante en un menor y el titular en un adulto.',
        ];
    }

    /**
     * Secciones validadas con sus valores normalizados (§2.14.2).
     *
     * @return array<string, array<string, mixed>>
     */
    public function sections(): array
    {
        $data = $this->validated();
        $sections = [];

        if (isset($data['clinical'])) {
            $sections['clinical'] = [
                'cpod' => isset($data['clinical']['cpod']) ? (int) $data['clinical']['cpod'] : null,
                'ceod' => isset($data['clinical']['ceod']) ? (int) $data['clinical']['ceod'] : null,
                'plaque_index_pct' => round((float) $data['clinical']['plaque_index_pct'], 1),
                'active_lesions' => (int) $data['clinical']['active_lesions'],
            ];
        }
        if (isset($data['behavioral'])) {
            $sections['behavioral'] = [...$data['behavioral'], 'fluoride_toothpaste' => (bool) $data['behavioral']['fluoride_toothpaste']];
        }
        if (isset($data['sociodemographic'])) {
            $sections['sociodemographic'] = ['family_structure' => null, ...$data['sociodemographic']];
        }

        return $sections;
    }

    public function patient(): Patient
    {
        $set = $this->route('set');
        $patient = $set instanceof RiskVariableSet ? $set->patient : $this->route('patient');
        assert($patient instanceof Patient);

        return $patient;
    }

    /**
     * @return list<string>
     */
    private function sectionNames(): array
    {
        return array_values(array_filter(RiskVariableSetService::SECTIONS, fn (string $section) => $this->has($section)));
    }
}
