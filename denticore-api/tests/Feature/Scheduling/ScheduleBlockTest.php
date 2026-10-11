<?php

/*
 * Bloqueos de agenda y su impacto (TASK-065; SDD §2.10, §4.3.8; CUS-44; RF-143, RF-144, RN-47,
 * RN-82). El reloj se fija el domingo 01/11/2026 en Lima.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-01 12:00:00', 'America/Lima')));

/** @param  array<string, mixed>  $payload */
function postBlock(array $payload): TestResponse
{
    return test()->postJson('/api/v1/schedule-blocks', $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

/** Cita del lunes 02/11/2026 a la hora local indicada, de 30 minutos. */
function mondayAppointment(Tenant $tenant, User $dentist, string $time, array $attributes = []): Appointment
{
    $start = Carbon::parse("2026-11-02 {$time}", 'America/Lima')->utc();

    return Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'dentist_id' => $dentist->id,
        'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), ...$attributes,
    ]);
}

/** @return array{tenant: Tenant, dentist: User} */
function blockScenario(string $role = 'clinic_admin'): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $actor = test()->actingAsRole($role, $tenant);

    return ['tenant' => $tenant, 'dentist' => $role === 'dentist' ? $actor : User::factory()->for($tenant)->create(['role' => 'dentist'])];
}

/** @return array<string, string> */
function mondayMorning(User $dentist): array
{
    return [
        'dentist_id' => $dentist->uuid,
        'starts_at' => Carbon::parse('2026-11-02 08:00', 'America/Lima')->toIso8601String(),
        'ends_at' => Carbon::parse('2026-11-02 13:00', 'America/Lima')->toIso8601String(),
    ];
}

it('rejects a block over active appointments without a decision for each one', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = blockScenario();
    $first = mondayAppointment($tenant, $dentist, '09:00');
    $second = mondayAppointment($tenant, $dentist, '10:00', ['status' => 'confirmada']);
    mondayAppointment($tenant, $dentist, '11:00', ['status' => 'cancelada', 'cancel_reason' => 'paciente']);

    postBlock([...mondayMorning($dentist), 'kind' => 'capacitacion', 'reason' => 'Curso de actualización'])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-82')
        ->assertJsonValidationErrors(["decisions.{$first->uuid}", "decisions.{$second->uuid}"]);

    TenantContext::run($tenant, fn () => expect(ScheduleBlock::query()->count())->toBe(0));
})->group('T-123', 'RN-82', 'RF-144', 'CUS-44');

it('lists the active appointments a block would affect', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = blockScenario();
    $affected = mondayAppointment($tenant, $dentist, '09:00');
    mondayAppointment($tenant, $dentist, '14:00');

    $this->postJson('/api/v1/schedule-blocks/impact', mondayMorning($dentist))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $affected->uuid)
        ->assertJsonPath('data.0.status', 'programada')
        ->assertJsonPath('data.0.dentist.id', $dentist->uuid);
})->group('RF-144', 'CUS-44');

it('creates a block of a dentist or of the whole clinic and lists them', function () {
    ['dentist' => $dentist] = blockScenario();

    postBlock([...mondayMorning($dentist), 'kind' => 'vacaciones', 'reason' => 'Vacaciones programadas'])
        ->assertCreated()
        ->assertJsonPath('data.dentist.id', $dentist->uuid)
        ->assertJsonPath('data.kind', 'vacaciones')
        ->assertJsonPath('data.starts_at', '2026-11-02T13:00:00+00:00');
    postBlock([
        'starts_at' => Carbon::parse('2026-12-25 00:00', 'America/Lima')->toIso8601String(),
        'ends_at' => Carbon::parse('2026-12-26 00:00', 'America/Lima')->toIso8601String(),
        'kind' => 'feriado', 'reason' => 'Navidad',
    ])->assertCreated()->assertJsonPath('data.dentist', null);

    $this->getJson("/api/v1/schedule-blocks?dentist_id={$dentist->uuid}")->assertOk()->assertJsonCount(2, 'data');
})->group('RF-143', 'CUS-44');

it('lets a dentist block and unblock only their own agenda', function () {
    ['tenant' => $tenant, 'dentist' => $dentist] = blockScenario('dentist');
    $colleague = User::factory()->for($tenant)->create(['role' => 'dentist']);

    $own = postBlock([...mondayMorning($dentist), 'kind' => 'otro', 'reason' => 'Trámite personal'])->assertCreated()->json('data.id');
    postBlock([...mondayMorning($colleague), 'kind' => 'otro', 'reason' => 'Trámite personal'])->assertForbidden();
    postBlock([...collect(mondayMorning($dentist))->except('dentist_id')->all(), 'kind' => 'cierre', 'reason' => 'Cierre de la clínica'])->assertForbidden();

    $colleagueBlock = ScheduleBlock::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $colleague->id]);
    $this->deleteJson("/api/v1/schedule-blocks/{$colleagueBlock->uuid}")->assertForbidden();
    $this->deleteJson("/api/v1/schedule-blocks/{$own}")->assertNoContent();
})->group('CUS-44', 'RF-004');

it('validates the interval, the kind and the reason', function (array $payload, string $field) {
    ['dentist' => $dentist] = blockScenario();

    postBlock([...mondayMorning($dentist), 'kind' => 'capacitacion', 'reason' => 'Curso', ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'end before start' => [['ends_at' => '2026-11-02T12:00:00+00:00'], 'ends_at'],
    'unknown kind' => [['kind' => 'almuerzo'], 'kind'],
    'missing reason' => [['reason' => ''], 'reason'],
    'unknown dentist' => [['dentist_id' => '00000000-0000-4000-8000-000000000000'], 'dentist_id'],
])->group('RF-143');
