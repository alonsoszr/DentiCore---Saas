<?php

/*
 * Cálculo del presupuesto (TASK-057; SDD §5.4.1 y filas T-081/T-082 de §6.3;
 * RN-29, RN-30, RNF-001, DI-05). Los casos esperados de T-081 corresponden a CA-35.1.
 */

use App\Modules\Treatment\Services\BudgetCalculator;
use App\Support\Money\Money;
use Tests\Support\SeededAmounts;

/**
 * Redondeo HALF_UP de $numerator/$divisor en céntimos enteros, sin brick/math.
 * Oráculo independiente para RN-29/RN-30 (RNF-001).
 */
function budgetRoundHalfUp(int $numerator, int $divisor): int
{
    $quotient = intdiv($numerator, $divisor);
    $remainder = $numerator % $divisor;

    // Numerador no negativo en este dominio (precios ≥ 0, cantidades ≥ 1).
    if ($remainder * 2 >= $divisor) {
        $quotient++;
    }

    return $quotient;
}

/**
 * Subtotal de una línea según RN-29 (precio × cantidad × (1 − descuento/100)),
 * en céntimos: redondeo HALF_UP del numerador exacto.
 */
function budgetReferenceSubtotal(int $priceCents, int $quantity, int $discountHundredths): int
{
    return budgetRoundHalfUp($priceCents * $quantity * (10_000 - $discountHundredths), 10_000);
}

/**
 * Implementación de referencia del presupuesto completo (RN-29, RN-30) en céntimos enteros.
 *
 * @param  list<array{0: int, 1: int, 2: int}>  $lines  [precio_en_céntimos, cantidad, descuento_en_centésimas]
 * @return array{lineSubtotals: list<int>, subtotal: int, discountTotal: int, base: int, igv: int, total: int}
 */
function budgetReferenceTotals(array $lines, bool $pricesIncludeIgv): array
{
    $lineSubtotals = [];
    $subtotal = 0;
    $net = 0;

    foreach ($lines as [$priceCents, $quantity, $discountHundredths]) {
        $lineSubtotal = budgetReferenceSubtotal($priceCents, $quantity, $discountHundredths);

        $lineSubtotals[] = $lineSubtotal;
        $subtotal += $priceCents * $quantity;
        $net += $lineSubtotal;
    }

    $discountTotal = $subtotal - $net;

    if ($pricesIncludeIgv) {
        $total = $net;
        $base = budgetRoundHalfUp($total * 100, 118); // total / 1,18 (RN-30)
        $igv = $total - $base;
    } else {
        $base = $net;
        $igv = budgetRoundHalfUp($net * 18, 100);
        $total = $base + $igv;
    }

    return compact('lineSubtotals', 'subtotal', 'discountTotal', 'base', 'igv', 'total');
}

it('computes total 294.00, base 249.15 and igv 44.85 with igv included', function () {
    $result = BudgetCalculator::calculate([
        ['unit_price' => '150.00', 'quantity' => 1, 'discount_pct' => '0'],
        ['unit_price' => '80.00', 'quantity' => 2, 'discount_pct' => '10'],
    ], pricesIncludeIgv: true);

    $subtotals = array_map(fn (Money $money) => (string) $money, $result['line_subtotals']);

    expect($subtotals)->toBe(['150.00', '144.00'])
        ->and((string) $result['subtotal'])->toBe('310.00')
        ->and((string) $result['discount_total'])->toBe('16.00')
        ->and((string) $result['base_amount'])->toBe('249.15')
        ->and((string) $result['igv_amount'])->toBe('44.85')
        ->and((string) $result['total'])->toBe('294.00')
        ->and($result['base_amount']->plus($result['igv_amount'])->equals($result['total']))->toBeTrue();
})->group('T-081', 'CA-35.1', 'RN-29', 'RN-30', 'RNF-001');

it('applies the platform IGV rate copied into the budget', function () {
    $lines = [['unit_price' => '100.00', 'quantity' => 1, 'discount_pct' => '0']];

    $excluded = BudgetCalculator::calculate($lines, pricesIncludeIgv: false, igvRate: '0.1000');
    $included = BudgetCalculator::calculate($lines, pricesIncludeIgv: true, igvRate: '0.1000');

    expect((string) $excluded['igv_amount'])->toBe('10.00')
        ->and((string) $excluded['total'])->toBe('110.00')
        ->and((string) $included['base_amount'])->toBe('90.91')
        ->and((string) $included['igv_amount'])->toBe('9.09')
        ->and((string) $included['total'])->toBe('100.00')
        ->and((string) BudgetCalculator::calculate($lines, false)['total'])->toBe('118.00');
})->group('RN-30', 'DD-07');

it('matches the reference implementation in 10000 random budgets', function () {
    $random = new SeededAmounts;

    for ($case = 0; $case < 10_000; $case++) {
        $lines = [];
        $referenceLines = [];

        foreach (range(1, $random->cents(1, 20)) as $i) {
            $priceCents = $random->cents(0, 9_999_999);
            $quantity = $random->cents(1, 32);
            $discountHundredths = $random->cents(0, 10_000);

            $lines[] = [
                'unit_price' => SeededAmounts::format($priceCents),
                'quantity' => $quantity,
                'discount_pct' => SeededAmounts::format($discountHundredths),
            ];
            $referenceLines[] = [$priceCents, $quantity, $discountHundredths];
        }

        $pricesIncludeIgv = $case % 2 === 0;
        $expected = budgetReferenceTotals($referenceLines, $pricesIncludeIgv);
        $actual = BudgetCalculator::calculate($lines, $pricesIncludeIgv);
        $format = fn (int $cents) => SeededAmounts::format($cents);
        $context = "semilla {$random->seed}, caso {$case}";

        expect((string) $actual['subtotal'])->toBe($format($expected['subtotal']), $context)
            ->and((string) $actual['discount_total'])->toBe($format($expected['discountTotal']), $context)
            ->and((string) $actual['base_amount'])->toBe($format($expected['base']), $context)
            ->and((string) $actual['igv_amount'])->toBe($format($expected['igv']), $context)
            ->and((string) $actual['total'])->toBe($format($expected['total']), $context);

        foreach ($actual['line_subtotals'] as $i => $money) {
            expect((string) $money)->toBe($format($expected['lineSubtotals'][$i]), $context);
        }
    }
})->group('T-082', 'RN-29', 'RN-30', 'RN-44', 'RNF-001');
