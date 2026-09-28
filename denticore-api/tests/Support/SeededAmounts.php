<?php

namespace Tests\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generador de importes con semilla para pruebas de propiedades (SDD §6.1; RNF-001):
 * la misma semilla produce siempre los mismos casos, así un fallo es reproducible.
 */
final class SeededAmounts
{
    private Randomizer $randomizer;

    public function __construct(public readonly int $seed = 20260927)
    {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * Importe en céntimos enteros entre $minCents y $maxCents (referencia exacta).
     */
    public function cents(int $minCents = 0, int $maxCents = 9_999_999_999): int
    {
        return $this->randomizer->getInt($minCents, $maxCents);
    }

    /**
     * Cadena decimal "1234.56" equivalente a un importe en céntimos.
     */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }
}
