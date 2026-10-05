<?php

namespace App\Modules\Platform\Services;

/**
 * RUC de la SUNAT (RF-014, SRS §11.1): 11 dígitos con prefijo 10 o 20 y dígito verificador
 * módulo 11. Los 10 primeros dígitos se multiplican por 5, 4, 3, 2, 7, 6, 5, 4, 3, 2; el
 * verificador es 11 − (suma mod 11), con 10 → 0 y 11 → 1.
 */
final class RucValidator
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function isValid(string $ruc): bool
    {
        if (preg_match('/^(10|20)\d{9}$/', $ruc) !== 1) {
            return false;
        }

        return self::checkDigit(substr($ruc, 0, 10)) === (int) $ruc[10];
    }

    /**
     * @param  string  $firstTenDigits  Los 10 primeros dígitos del RUC.
     */
    public static function checkDigit(string $firstTenDigits): int
    {
        $sum = 0;
        foreach (self::WEIGHTS as $position => $weight) {
            $sum += (int) $firstTenDigits[$position] * $weight;
        }

        return (11 - $sum % 11) % 10;
    }
}
