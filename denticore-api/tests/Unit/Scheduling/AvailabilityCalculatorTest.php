<?php

/*
 * T-118 (SDD §5.7, §6.3.7; DD-24, RF-146, RN-47, RN-48): inicios cada 15 min dentro de las franjas,
 * sin bloqueos ni citas activas. La clínica está en America/Lima (UTC−5).
 */

use App\Modules\Scheduling\Services\AvailabilityCalculator;
use Carbon\CarbonImmutable;

const LIMA = 'America/Lima';

/** Franja de lunes vigente desde el 2026-01-01. */
function mondaySlot(string $start, string $end): array
{
    return ['weekday' => 1, 'start_time' => $start, 'end_time' => $end, 'valid_from' => '2026-01-01', 'valid_until' => null];
}

/** Intervalo ocupado del lunes 02/11/2026 en hora local. */
function busy(string $start, string $end): array
{
    return [CarbonImmutable::parse("2026-11-02 {$start}", LIMA), CarbonImmutable::parse("2026-11-02 {$end}", LIMA)];
}

/** @return list<string> Inicios en hora local `H:i`. */
function localStarts(array $starts): array
{
    return array_map(fn (CarbonImmutable $start) => $start->setTimezone(LIMA)->format('H:i'), $starts);
}

$sunday = CarbonImmutable::parse('2026-11-01 12:00', LIMA);

it('offers starts every 15 minutes from the beginning of the slot that fit in it', function () use ($sunday) {
    $starts = AvailabilityCalculator::candidates([mondaySlot('09:00', '10:00')], [], 30, '2026-11-02', '2026-11-02', LIMA, $sunday);

    expect(localStarts($starts))->toBe(['09:00', '09:15', '09:30'])
        ->and($starts[0]->toIso8601String())->toBe('2026-11-02T14:00:00+00:00');
})->group('T-118', 'DD-24', 'RF-146');

it('excludes starts that overlap a block or an active appointment', function () use ($sunday) {
    $starts = AvailabilityCalculator::candidates(
        [mondaySlot('09:00', '11:00')],
        [busy('09:30', '10:00'), busy('10:30', '11:00')],
        30, '2026-11-02', '2026-11-02', LIMA, $sunday,
    );

    // 09:15 se cruzaría con 09:30; 10:15 con 10:30.
    expect(localStarts($starts))->toBe(['09:00', '10:00']);
})->group('T-118', 'RN-46', 'RN-47');

it('uses only slots of the weekday that are valid on each date', function () use ($sunday) {
    $slots = [
        mondaySlot('09:00', '09:30'),
        ['weekday' => 2, 'start_time' => '15:00', 'end_time' => '15:30', 'valid_from' => '2026-01-01', 'valid_until' => null],
        ['weekday' => 1, 'start_time' => '16:00', 'end_time' => '16:30', 'valid_from' => '2026-11-09', 'valid_until' => null],
    ];

    $starts = AvailabilityCalculator::candidates($slots, [], 30, '2026-11-02', '2026-11-09', LIMA, $sunday);

    expect(array_map(fn (CarbonImmutable $start) => $start->setTimezone(LIMA)->format('d H:i'), $starts))
        ->toBe(['02 09:00', '03 15:00', '09 09:00', '09 16:00']);
})->group('T-118', 'RF-142');

it('does not offer starts in the past or, in the portal, earlier than the notice', function () {
    $mondayMorning = CarbonImmutable::parse('2026-11-02 09:20', LIMA);

    expect(localStarts(AvailabilityCalculator::candidates([mondaySlot('09:00', '12:00')], [], 30, '2026-11-02', '2026-11-02', LIMA, $mondayMorning))[0])
        ->toBe('09:30')
        ->and(localStarts(AvailabilityCalculator::candidates([mondaySlot('09:00', '12:00')], [], 30, '2026-11-02', '2026-11-02', LIMA, $mondayMorning->addHours(2))))
        ->toBe(['11:30']);
})->group('T-118', 'RN-48');
