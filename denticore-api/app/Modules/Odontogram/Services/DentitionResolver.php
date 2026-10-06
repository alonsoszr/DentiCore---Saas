<?php

namespace App\Modules\Odontogram\Services;

/**
 * Dentición que el odontograma muestra por defecto según la edad (SDD §5.3; DD-25, RF-092). Solo
 * afecta la vista: la validación usa la pieza (ClinicalValidator).
 */
final class DentitionResolver
{
    /**
     * @return 'temporal'|'mixta'|'permanente'
     */
    public static function default(int $ageYears): string
    {
        return match (true) {
            $ageYears < 6 => 'temporal',
            $ageYears <= 12 => 'mixta',
            default => 'permanente',
        };
    }
}
