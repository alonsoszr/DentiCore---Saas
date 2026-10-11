<?php

/*
 * Horario laboral por odontólogo (TASK-065; SDD §2.10, §4.3.8; CUS-44; RF-142, RF-144, RN-82,
 * DI-09). La clínica está en America/Lima y el reloj se fija el domingo 01/11/2026.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-01 12:00:00', 'America/Lima')));

/** @param  array<string, mixed>  $payload */
function putWorkSchedule(User $dentist, array $payload): TestResponse
{
    return test()->putJson("/api/v1/dentists/{$dentist->uuid}/work-schedules", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

/** @return array{tenant: Tenant, dentist: User} */
function workScheduleScenario(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    test()->actingAsRole('clinic_admin', $tenant);

    return ['tenant' => $tenant, 'dentist' => User::factory()->for($tenant)->create(['role' => 'dentist'])];
}

it('lets the clinic administrator replace the weekly schedule of a dentist', function () {
    ['dentist' => $dentist] = workScheduleScenario();

    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
        ['weekday' => 1, 'start_time' => '14:00', 'end_time' => '18:00'],
        ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '13:00'],
    ]])->assertOk()->assertJsonCount(3, 'data');

    $this->getJson("/api/v1/dentists/{$dentist->uuid}/work-schedules")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.weekday', 1)
        ->assertJsonPath('data.0.start_time', '09:00')
        ->assertJsonPath('data.0.end_time', '13:00')
        ->assertJsonPath('data.0.valid_from', '2026-11-01')
        ->assertJsonPath('data.0.valid_until', null);
})->group('RF-142', 'CUS-44');

it('rejects overlapping slots on the same weekday', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = workScheduleScenario();

    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [
        ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00'],
        ['weekday' => 1, 'start_time' => '12:00', 'end_time' => '15:00'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('slots.1.start_time');

    TenantContext::run($tenant, fn () => expect(WorkSchedule::query()->count())->toBe(0));
})->group('T-124', 'RF-142', 'DI-09');

it('validates the weekday, the times and a start date not in the past', function (array $payload, string $field) {
    ['dentist' => $dentist] = workScheduleScenario();

    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']], ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'weekday 8' => [['slots' => [['weekday' => 8, 'start_time' => '09:00', 'end_time' => '13:00']]], 'slots.0.weekday'],
    'end before start' => [['slots' => [['weekday' => 1, 'start_time' => '13:00', 'end_time' => '09:00']]], 'slots.0.end_time'],
    'past date' => [['valid_from' => '2026-10-31'], 'valid_from'],
])->group('RF-142');

it('keeps the previous schedule until the day before the new one starts', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = workScheduleScenario();
    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']]])->assertOk();

    putWorkSchedule($dentist, ['valid_from' => '2026-11-16', 'slots' => [['weekday' => 2, 'start_time' => '15:00', 'end_time' => '19:00']]])->assertOk();

    TenantContext::run($tenant, function () {
        $slots = WorkSchedule::query()->orderBy('valid_from')->get();
        expect($slots)->toHaveCount(2)
            ->and($slots[0]->valid_until?->toDateString())->toBe('2026-11-15')
            ->and($slots[1]->valid_from->toDateString())->toBe('2026-11-16')
            ->and($slots[1]->valid_until)->toBeNull();
    });
})->group('RF-142');

it('rejects a schedule that leaves active appointments out without a decision for each one', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = workScheduleScenario();
    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '18:00']]])->assertOk();
    // Lunes 02/11 de 15:00 a 15:30 en Lima (20:00 UTC).
    $appointment = Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'dentist_id' => $dentist->id,
        'starts_at' => Carbon::parse('2026-11-02 15:00', 'America/Lima')->utc(), 'ends_at' => Carbon::parse('2026-11-02 15:30', 'America/Lima')->utc(),
    ]);

    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']]])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-82')
        ->assertJsonValidationErrors("decisions.{$appointment->uuid}");

    // Nada cambió; un horario que la sigue cubriendo sí se acepta.
    TenantContext::run($tenant, fn () => expect(WorkSchedule::query()->sole()->end_time)->toStartWith('18:00'));
    putWorkSchedule($dentist, ['valid_from' => '2026-11-01', 'slots' => [['weekday' => 1, 'start_time' => '14:00', 'end_time' => '16:00']]])->assertOk();
})->group('RF-144', 'RN-82', 'CUS-44');

it('answers 404 for a user that is not a dentist of the clinic', function () {
    ['tenant' => $tenant] = workScheduleScenario();
    $receptionist = User::factory()->for($tenant)->create(['role' => 'receptionist']);
    $foreignDentist = User::factory()->for(Tenant::factory())->create(['role' => 'dentist']);

    $this->getJson("/api/v1/dentists/{$receptionist->uuid}/work-schedules")->assertNotFound();
    $this->getJson("/api/v1/dentists/{$foreignDentist->uuid}/work-schedules")->assertNotFound();
})->group('RF-004', 'RNF-101');
