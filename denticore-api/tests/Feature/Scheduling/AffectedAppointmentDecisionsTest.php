<?php

/*
 * Decisiones sobre las citas afectadas por un bloqueo o un cambio de horario (TASK-068; SDD §5.7;
 * CUS-44, CUS-48; RF-144, RN-46, RN-82). La clínica está en America/Lima y el reloj se fija el
 * domingo 01/11/2026 a las 12:00.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-01 12:00:00', 'America/Lima')));

function lima(string $time, string $date = '2026-11-02'): Carbon
{
    return Carbon::parse("{$date} {$time}", 'America/Lima')->utc();
}

/**
 * Clínica con un odontólogo de lunes 09:00–13:00 y dos citas el lunes 02/11 (09:00 y 10:00) de
 * pacientes que otorgaron la finalidad (b).
 *
 * @return array{tenant: Tenant, admin: User, dentist: User, first: Appointment, second: Appointment}
 */
function decisionsScenario(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $admin = test()->actingAsRole('clinic_admin', $tenant);
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    WorkSchedule::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00', 'valid_from' => '2026-10-01']);

    $appointment = function (string $time) use ($tenant, $admin, $dentist): Appointment {
        $patient = TenantContext::run($tenant, function () use ($tenant, $admin) {
            $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'email' => Str::random(8).'@example.test']);
            app(ConsentService::class)->grant($patient, [
                'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number, 'purpose_notifications' => true,
            ], $admin, '127.0.0.1');

            return $patient;
        });

        return Appointment::factory()->create([
            'tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'patient_id' => $patient->id,
            'starts_at' => lima($time), 'ends_at' => lima($time)->addMinutes(30),
        ]);
    };

    return ['tenant' => $tenant, 'admin' => $admin, 'dentist' => $dentist, 'first' => $appointment('09:00'), 'second' => $appointment('10:00')];
}

/** @param  array<string, mixed>  $overrides */
function morningBlock(User $dentist, array $overrides = []): array
{
    return [
        'dentist_id' => $dentist->uuid,
        'starts_at' => lima('08:00')->toIso8601String(),
        'ends_at' => lima('11:00')->toIso8601String(),
        'kind' => 'capacitacion',
        'reason' => 'Curso de bioseguridad',
        ...$overrides,
    ];
}

function mailsOf(Tenant $tenant, string $event): int
{
    return Notification::query()->where('tenant_id', $tenant->id)->where('event', $event)->count();
}

it('reschedules and cancels the affected appointments in the same transaction as the block', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'first' => $first, 'second' => $second] = decisionsScenario();

    $this->postJson('/api/v1/schedule-blocks', morningBlock($dentist, ['decisions' => [
        ['appointment_id' => $first->uuid, 'action' => 'reprogramar', 'new_starts_at' => lima('11:30')->toIso8601String()],
        ['appointment_id' => $second->uuid, 'action' => 'cancelar'],
    ]]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    TenantContext::run($tenant, function () use ($first, $second) {
        expect(ScheduleBlock::query()->count())->toBe(1)
            ->and($first->fresh()->starts_at->equalTo(lima('11:30')))->toBeTrue()
            ->and($first->fresh()->status)->toBe('programada')
            ->and($second->fresh()->status)->toBe('cancelada')
            ->and($second->fresh()->cancel_reason)->toBe('clinica')
            ->and($second->fresh()->cancel_detail)->toBe('Bloqueo de agenda: Curso de bioseguridad');
    });
    expect(mailsOf($tenant, 'cita_modificada'))->toBe(1)
        ->and(mailsOf($tenant, 'cita_cancelada'))->toBe(1);
})->group('RF-144', 'RN-82', 'CUS-44', 'CUS-48');

it('applies nothing when one rescheduling collides with another appointment', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'first' => $first, 'second' => $second] = decisionsScenario();
    // Ya hay una cita a las 12:00: reprogramar la primera a las 12:00 choca (23P01 → 409).
    Appointment::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'starts_at' => lima('12:00'), 'ends_at' => lima('12:30')]);

    $this->postJson('/api/v1/schedule-blocks', morningBlock($dentist, ['decisions' => [
        ['appointment_id' => $second->uuid, 'action' => 'cancelar'],
        ['appointment_id' => $first->uuid, 'action' => 'reprogramar', 'new_starts_at' => lima('12:00')->toIso8601String()],
    ]]), ['Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('rule', 'RN-46');

    TenantContext::run($tenant, function () use ($first, $second) {
        expect(ScheduleBlock::query()->count())->toBe(0)
            ->and($first->fresh()->starts_at->equalTo(lima('09:00')))->toBeTrue()
            ->and($second->fresh()->status)->toBe('programada');
    });
    expect(mailsOf($tenant, 'cita_cancelada'))->toBe(0)
        ->and(mailsOf($tenant, 'cita_modificada'))->toBe(0);
})->group('RN-82', 'RN-46', 'RF-011');

it('requires a decision for every affected appointment and only for them', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'first' => $first, 'second' => $second] = decisionsScenario();
    $unaffected = Appointment::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'starts_at' => lima('12:00'), 'ends_at' => lima('12:30')]);

    $post = fn (array $decisions) => $this->postJson('/api/v1/schedule-blocks', morningBlock($dentist, ['decisions' => $decisions]), ['Idempotency-Key' => (string) Str::uuid()]);

    $post([['appointment_id' => $first->uuid, 'action' => 'cancelar']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors("decisions.{$second->uuid}")
        ->assertJsonMissingValidationErrors("decisions.{$first->uuid}");
    $post([
        ['appointment_id' => $first->uuid, 'action' => 'cancelar'],
        ['appointment_id' => $second->uuid, 'action' => 'cancelar'],
        ['appointment_id' => $unaffected->uuid, 'action' => 'cancelar'],
    ])->assertUnprocessable()->assertJsonValidationErrors("decisions.{$unaffected->uuid}");
    $post([
        ['appointment_id' => $first->uuid, 'action' => 'reprogramar'],
        ['appointment_id' => $second->uuid, 'action' => 'cancelar'],
    ])->assertUnprocessable()->assertJsonValidationErrors('decisions.0.new_starts_at');

    TenantContext::run($tenant, fn () => expect(ScheduleBlock::query()->count())->toBe(0));
})->group('RN-82', 'RF-144');

it('does not let a dentist decide on the affected appointments', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'second' => $second] = decisionsScenario();
    $this->actingWithToken($dentist);

    $this->postJson('/api/v1/schedule-blocks', morningBlock($dentist, ['decisions' => [
        ['appointment_id' => $second->uuid, 'action' => 'cancelar'],
    ]]), ['Idempotency-Key' => (string) Str::uuid()])
        // AuthorizationException (no el 2FA ni el rol): el odontólogo sí puede crear el bloqueo.
        ->assertForbidden()
        ->assertJsonPath('detail', 'No tiene permiso para realizar esta acción.');
})->group('CUS-48', 'RF-004');

it('cancels the appointments left out by a reduced work schedule', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'first' => $first, 'second' => $second] = decisionsScenario();

    $this->putJson("/api/v1/dentists/{$dentist->uuid}/work-schedules", [
        'valid_from' => '2026-11-01',
        'slots' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '09:30']],
        'decisions' => [['appointment_id' => $second->uuid, 'action' => 'cancelar']],
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    TenantContext::run($tenant, function () use ($first, $second) {
        expect($first->fresh()->status)->toBe('programada')
            ->and($second->fresh()->status)->toBe('cancelada')
            ->and($second->fresh()->cancel_detail)->toBe('Cambio del horario laboral del odontólogo');
    });
    expect(mailsOf($tenant, 'cita_cancelada'))->toBe(1);
})->group('RF-144', 'RN-82', 'RF-142');
