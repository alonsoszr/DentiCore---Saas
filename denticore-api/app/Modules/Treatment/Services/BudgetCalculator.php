<?php

namespace App\Modules\Treatment\Services;

use App\Support\Money\Money;
use Brick\Math\BigDecimal;

/**
 * Cálculo del presupuesto (SDD §5.4.1; RN-29, RN-30, RNF-001, DI-05): validador de dominio puro,
 * sin dependencias de HTTP ni de base de datos. Cada importe se calcula con aritmética decimal
 * exacta (Money sobre brick/math, escala 2 y RoundingMode::HALF_UP); nunca usa float.
 */
final class BudgetCalculator
{
    /**
     * Tasa de IGV de la plataforma al 18 % (RN-30; DD-07 siembra `igv_rate` = 0.18).
     */
    private const IGV_RATE = '0.18';

    /**
     * Calcula subtotales por línea y los importes del presupuesto en las dos modalidades de
     * `prices_include_igv`. `subtotal` y `discount_total` son previos al IGV: el parámetro
     * `pricesIncludeIgv` solo decide cómo se desglosan base e IGV (RN-30).
     *
     * @param  list<array{unit_price: Money|string|int, quantity: int, discount_pct: string|int}>  $lines
     * @return array{line_subtotals: list<Money>, subtotal: Money, discount_total: Money, base_amount: Money, igv_amount: Money, total: Money}
     */
    public static function calculate(array $lines, bool $pricesIncludeIgv): array
    {
        $lineSubtotals = [];
        $subtotal = Money::zero();
        $net = Money::zero();

        foreach ($lines as $line) {
            $gross = Money::of($line['unit_price'])->multipliedBy($line['quantity']);
            $lineSubtotal = $gross->multipliedBy(self::discountFactor($line['discount_pct']));

            $lineSubtotals[] = $lineSubtotal;
            $subtotal = $subtotal->plus($gross);
            $net = $net->plus($lineSubtotal);
        }

        if ($pricesIncludeIgv) {
            $total = $net;
            $base = $total->dividedBy(BigDecimal::one()->plus(self::IGV_RATE));
            $igv = $total->minus($base);
        } else {
            $base = $net;
            $igv = $net->multipliedBy(self::IGV_RATE);
            $total = $base->plus($igv);
        }

        return [
            'line_subtotals' => $lineSubtotals,
            'subtotal' => $subtotal,
            'discount_total' => $subtotal->minus($net),
            'base_amount' => $base,
            'igv_amount' => $igv,
            'total' => $total,
        ];
    }

    /**
     * Factor `(1 − descuento/100)` en escala 6 (SDD §5.4.1) para un descuento exacto.
     */
    private static function discountFactor(string|int $discountPct): BigDecimal
    {
        return BigDecimal::one()->minus(BigDecimal::of((string) $discountPct)->dividedBy(100, 6));
    }
}
