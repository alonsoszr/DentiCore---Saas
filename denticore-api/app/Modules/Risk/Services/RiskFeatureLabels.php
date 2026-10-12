<?php

namespace App\Modules\Risk\Services;

/**
 * Etiquetas en español de las variables y de sus valores para la explicación (SDD §2.14.2,
 * §5.8.3; RF-161, RF-168): «Lesiones activas», «Pasta fluorada: sí».
 */
final class RiskFeatureLabels
{
    private const LABELS = [
        'age_years' => 'Edad',
        'cpod' => 'CPOD',
        'ceod' => 'ceod',
        'plaque_index_pct' => 'Índice de placa',
        'active_lesions' => 'Lesiones activas',
        'sugar_between_meals' => 'Azúcar entre comidas',
        'fluoride_toothpaste' => 'Pasta fluorada',
        'brushing_per_day' => 'Cepillado diario',
        'dental_visits_per_year' => 'Visitas al odontólogo por año',
        'education_level' => 'Nivel educativo',
        'employment_status' => 'Situación laboral',
        'family_structure' => 'Estructura familiar',
    ];

    private const VALUES = [
        'true' => 'sí',
        'false' => 'no',
        'sin_estudios' => 'sin estudios',
        'tecnica' => 'técnica',
        'desempleado' => 'desempleado',
        '4+' => '4 o más',
        '3+' => '3 o más',
        '2+' => '2 o más',
        '<1' => 'menos de 1',
    ];

    public static function label(string $feature): string
    {
        return self::LABELS[$feature] ?? $feature;
    }

    /** Valor como texto para la presentación; sin valor, «—». */
    public static function value(string $feature, ?string $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($feature === 'plaque_index_pct') {
            return str_replace('.', ',', $value).' %';
        }

        return self::VALUES[$value] ?? $value;
    }

    /** Valor del motor como texto para guardar en `explanation_individual` (§2.14.3). */
    public static function stored(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }
}
