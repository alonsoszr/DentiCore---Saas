<?php

namespace App\Modules\Risk\Services;

use App\Modules\Odontogram\Services\DentitionResolver;
use App\Modules\Risk\Models\RiskVariableSet;
use Carbon\CarbonInterface;

/**
 * Dominios y completitud de las variables de riesgo (SDD §2.14.2; RF-161, RN-58, DI-20). Calcula
 * qué falta para predecir según el grupo etario y la dentición vigente: CPOD si es permanente o
 * mixta, ceod si es temporal o mixta, y estructura familiar solo en menores.
 */
final class RiskVariableValidator
{
    public const SUGAR = ['0', '1', '2', '3', '4+'];

    public const BRUSHING = ['0', '1', '2', '3+'];

    public const VISITS = ['<1', '1', '2+'];

    public const EDUCATION = ['sin_estudios', 'primaria', 'secundaria', 'tecnica', 'universitaria'];

    public const EMPLOYMENT = ['formal', 'informal', 'desempleado', 'otra'];

    public const FAMILY = ['biparental', 'monoparental', 'extendida', 'otra'];

    /** Mensaje de CA-55.4 cuando la captura tiene más de 6 meses (RN-58). */
    public const STALE = 'variables capturadas hace más de 6 meses';

    /** Las 12 variables que se envían al motor (contrato 1.0). */
    public const FEATURES = [
        'age_years', 'cpod', 'ceod', 'plaque_index_pct', 'active_lesions', 'sugar_between_meals', 'fluoride_toothpaste',
        'brushing_per_day', 'dental_visits_per_year', 'education_level', 'employment_status', 'family_structure',
    ];

    /**
     * Campos de cada sección que exige la predicción para la edad indicada.
     *
     * @return array{clinical: list<string>, behavioral: list<string>, sociodemographic: list<string>}
     */
    public static function required(int $ageYears, bool $isMinor): array
    {
        $dentition = DentitionResolver::default($ageYears);
        $index = match ($dentition) {
            'temporal' => ['ceod'],
            'mixta' => ['cpod', 'ceod'],
            default => ['cpod'],
        };

        return [
            'clinical' => [...$index, 'plaque_index_pct', 'active_lesions'],
            'behavioral' => ['sugar_between_meals', 'fluoride_toothpaste', 'brushing_per_day', 'dental_visits_per_year'],
            'sociodemographic' => ['respondent', 'education_level', 'employment_status', ...($isMinor ? ['family_structure'] : [])],
        ];
    }

    /**
     * Campos que faltan para predecir; con `$now`, además la vigencia de 6 meses (CA-55.4).
     *
     * @return list<string>
     */
    public static function missing(RiskVariableSet $set, ?CarbonInterface $now = null): array
    {
        $missing = [];

        foreach (self::required($set->age_years, $set->age_group === 'menor') as $section => $fields) {
            foreach ($fields as $field) {
                if (($set->{$section}[$field] ?? null) === null) {
                    $missing[] = $field;
                }
            }
        }

        if ($now !== null && $missing === []) {
            $oldest = collect([$set->clinical_captured_at, $set->sociodemographic_captured_at])->filter()->min();
            if ($oldest === null || $oldest->lt($now->copy()->subMonths(6))) {
                $missing[] = self::STALE;
            }
        }

        return $missing;
    }

    /**
     * Variables del contrato del motor, sin datos personales (RF-167, RES-04).
     *
     * @return array<string, mixed>
     */
    public static function features(RiskVariableSet $set): array
    {
        return [
            'age_years' => $set->age_years,
            'cpod' => $set->clinical['cpod'] ?? null,
            'ceod' => $set->clinical['ceod'] ?? null,
            'plaque_index_pct' => (float) ($set->clinical['plaque_index_pct'] ?? 0),
            'active_lesions' => $set->clinical['active_lesions'] ?? null,
            'sugar_between_meals' => $set->behavioral['sugar_between_meals'] ?? null,
            'fluoride_toothpaste' => $set->behavioral['fluoride_toothpaste'] ?? null,
            'brushing_per_day' => $set->behavioral['brushing_per_day'] ?? null,
            'dental_visits_per_year' => $set->behavioral['dental_visits_per_year'] ?? null,
            'education_level' => $set->sociodemographic['education_level'] ?? null,
            'employment_status' => $set->sociodemographic['employment_status'] ?? null,
            'family_structure' => $set->age_group === 'menor' ? ($set->sociodemographic['family_structure'] ?? null) : null,
        ];
    }
}
