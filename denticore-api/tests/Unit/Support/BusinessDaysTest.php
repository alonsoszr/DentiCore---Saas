<?php

/*
 * Días hábiles de los plazos ARCO (TASK-103; SDD §5.11; DD-26, RN-69, CA-64.1). T-142.
 */

use App\Support\Time\BusinessDays;
use Carbon\CarbonImmutable;

it('sets the deadline of a Monday request to the Monday two weeks later', function () {
    // CA-64.1: recibida el lunes 2 de noviembre de 2026 → vence el lunes 16.
    expect(BusinessDays::addWorkingDays(CarbonImmutable::parse('2026-11-02'), 10)->toDateString())->toBe('2026-11-16');
})->group('T-142', 'CA-64.1', 'RN-69', 'DD-26');

it('counts from the next business day and skips weekends without holidays', function (string $received, string $due) {
    expect(BusinessDays::addWorkingDays(CarbonImmutable::parse($received), 10)->toDateString())->toBe($due);
})->with([
    'viernes' => ['2026-11-06', '2026-11-20'],
    'sábado' => ['2026-11-07', '2026-11-20'],
    'domingo' => ['2026-11-08', '2026-11-20'],
    // DD-26: no se descuentan feriados (8 de diciembre, Inmaculada Concepción).
    'con feriado' => ['2026-12-07', '2026-12-21'],
    'fin de año' => ['2026-12-28', '2027-01-11'],
])->group('DD-26', 'RN-69');

it('counts the business days left until the deadline', function (string $today, string $due, int $left) {
    expect(BusinessDays::remaining(CarbonImmutable::parse($today), CarbonImmutable::parse($due)))->toBe($left);
})->with([
    'aviso del jueves' => ['2026-11-12', '2026-11-16', 2],
    'el mismo día' => ['2026-11-16', '2026-11-16', 0],
    'desde el sábado' => ['2026-11-14', '2026-11-16', 1],
    'vencida' => ['2026-11-18', '2026-11-16', -2],
])->group('DD-26', 'CUS-64');

it('keeps the time of day out of the deadline', function () {
    expect(BusinessDays::addWorkingDays(CarbonImmutable::parse('2026-11-02 23:59:59'), 1)->toDateTimeString())->toBe('2026-11-03 00:00:00');
})->group('DD-26');
