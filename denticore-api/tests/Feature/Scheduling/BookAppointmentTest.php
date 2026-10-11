<?php

/*
 * Reserva, reprogramación, cancelación y confirmación de citas (TASK-067; SDD §4.3.8, §5.7;
 * CUS-47 a CUS-49; RF-147, RF-149, RF-150; RN-46 a RN-49, RN-52, RN-74, RN-80; T-111 a T-117).
 * La clínica está en America/Lima y el reloj se fija el domingo 01/11/2026 a las 12:00.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\Notification;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Scheduling\Models\WorkSchedule;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-01 12:00:00', 'America/Lima')));

/** Lunes 02/11/2026 a la hora local indicada, en ISO 8601 con el desfase de Lima. */
function at(string $time, string $date = '2026-11-02'): string
{
    return Carbon::parse("{$date} {$time}", 'America/Lima')->toIso8601String();
}

/**
 * Clínica en Lima con un odontólogo de lunes 09:00–13:00, un tipo de 30 min y un paciente adulto
 * con consentimiento; `$notifications` indica si otorgó la finalidad (b).
 *
 * @return array{tenant: Tenant, receptionist: User, dentist: User, type: AppointmentType, patient: Patient}
 */
function bookingScenario(bool $notifications = true): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    $receptionist = test()->actingAsRole('receptionist', $tenant);
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist', 'name' => 'Dra. Ana Torres']);
    WorkSchedule::factory()->create(['tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']);
    $type = AppointmentType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Control', 'default_duration_min' => 30]);
    $patient = TenantContext::run($tenant, function () use ($tenant, $receptionist, $notifications) {
        $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', 'email' => 'paciente@example.test']);
        app(ConsentService::class)->grant($patient, [
            'channel' => 'presencial', 'confirmation_document_number' => $patient->document_number, 'purpose_notifications' => $notifications,
        ], $receptionist, '127.0.0.1');

        return $patient;
    });

    return compact('tenant', 'receptionist', 'dentist', 'type', 'patient');
}

/** @param  array<string, mixed>  $overrides */
function book(array $scenario, string $startsAt, array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/appointments', [
        'patient_id' => $scenario['patient']->uuid,
        'dentist_id' => $scenario['dentist']->uuid,
        'appointment_type_id' => $scenario['type']->uuid,
        'starts_at' => $startsAt,
        ...$overrides,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
}

/** @return list<Notification> */
function appointmentMails(Tenant $tenant, string $event): array
{
    // `notifications` no tiene Global Scope (tenant_id puede ser nulo): se filtra por clínica.
    return Notification::query()->where('tenant_id', $tenant->id)->where('event', $event)->get()->all();
}

it('books an appointment and rejects an overlapping one with the same dentist', function () {
    $scenario = bookingScenario();

    book($scenario, at('09:00'))
        ->assertCreated()
        ->assertJsonPath('data.status', 'programada')
        ->assertJsonPath('data.origin', 'clinica')
        ->assertJsonPath('data.starts_at', '2026-11-02T14:00:00+00:00')
        ->assertJsonPath('data.ends_at', '2026-11-02T14:30:00+00:00')
        ->assertJsonPath('data.dentist.id', $scenario['dentist']->uuid);

    $other = TenantContext::run($scenario['tenant'], fn () => Patient::factory()->for($scenario['tenant'])->create());
    book($scenario, at('09:15'), ['patient_id' => $other->uuid])
        ->assertConflict()
        ->assertJsonPath('rule', 'RN-46')
        ->assertJsonPath('detail', 'El horario ya no está disponible.');
})->group('T-111', 'CA-47.1', 'RN-46', 'RF-147', 'CUS-47');

it('lets only one of two simultaneous bookings of the same slot through', function () {
    $scenario = bookingScenario();
    book($scenario, at('09:00'))->assertCreated();

    // La primera reserva sigue sin confirmar en la transacción de la prueba: otra conexión que
    // reserva el mismo intervalo queda esperando al EXCLUDE y no puede guardarse (DD-09).
    $concurrent = DB::connection('pgsql_concurrent');
    $error = null;
    try {
        $concurrent->transaction(function () use ($concurrent, $scenario) {
            $concurrent->select("select set_config('app.tenant_id', ?, true)", [(string) $scenario['tenant']->id]);
            $concurrent->statement("set local lock_timeout = '1s'");
            $concurrent->insert(
                "insert into appointments (tenant_id, patient_id, dentist_id, appointment_type_id, starts_at, ends_at, origin, created_by)
                 values (?, ?, ?, ?, ?, ?, 'clinica', ?)",
                [$scenario['tenant']->id, $scenario['patient']->id + 1000, $scenario['dentist']->id, $scenario['type']->id,
                    Carbon::parse(at('09:00'))->utc(), Carbon::parse(at('09:30'))->utc(), $scenario['receptionist']->id],
            );
        });
    } catch (QueryException $exception) {
        $error = (string) $exception->getCode();
    }

    expect($error)->toBe('55P03')
        ->and(TenantContext::run($scenario['tenant'], fn () => Appointment::query()->count()))->toBe(1);
})->group('T-112', 'CA-47.2', 'RNF-038', 'DD-09');

it('rejects an appointment that ends after the working hours or falls on a block', function () {
    $scenario = bookingScenario();

    book($scenario, at('12:45'))->assertUnprocessable()->assertJsonPath('rule', 'RN-47');
    book($scenario, at('09:00', '2026-11-03'))->assertUnprocessable()->assertJsonPath('rule', 'RN-47');

    ScheduleBlock::factory()->create(['tenant_id' => $scenario['tenant']->id, 'dentist_id' => null,
        'period' => '['.Carbon::parse(at('10:00'))->utc()->toIso8601String().','.Carbon::parse(at('11:00'))->utc()->toIso8601String().')']);
    book($scenario, at('10:30'))->assertUnprocessable()->assertJsonPath('detail', 'El horario se cruza con un bloqueo de la agenda.');
})->group('T-113', 'CA-47.3', 'RN-47', 'FE-2');

it('rejects appointments in the past, of an inactive type or for a deceased patient', function () {
    $scenario = bookingScenario();

    $this->travelTo(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    book($scenario, at('09:30'))->assertUnprocessable()->assertJsonPath('rule', 'RN-48');

    $inactive = AppointmentType::factory()->create(['tenant_id' => $scenario['tenant']->id, 'is_active' => false]);
    book($scenario, at('11:00'), ['appointment_type_id' => $inactive->uuid])->assertUnprocessable()->assertJsonPath('rule', 'RN-47');

    TenantContext::run($scenario['tenant'], fn () => $scenario['patient']->forceFill(['deceased_on' => '2026-10-30'])->save());
    book($scenario, at('11:00'))->assertUnprocessable()->assertJsonPath('rule', 'RN-80');
})->group('RN-48', 'RN-80', 'FE-3');

it('books the slot of a cancelled appointment', function () {
    $scenario = bookingScenario();
    $first = book($scenario, at('09:00'))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/appointments/{$first}/cancel", ['cancel_reason' => 'paciente'])->assertOk();

    book($scenario, at('09:00'))->assertCreated();
})->group('T-115', 'CA-47.5', 'RF-149');

it('queues exactly one confirmation only when the patient granted notifications', function () {
    $with = bookingScenario();
    book($with, at('09:00'))->assertCreated();

    $mails = appointmentMails($with['tenant'], 'cita_creada');
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->channel)->toBe('correo')
        ->and($mails[0]->payload['data'])->toEqual(['date' => '02/11/2026', 'time' => '09:00', 'dentist' => 'Dra. Ana Torres', 'type' => 'Control'])
        ->and($mails[0]->payload['links']['confirm'])->toStartWith(config('app.spa_url')."/c/{$with['tenant']->slug}/confirmar/");

    $without = bookingScenario(notifications: false);
    book($without, at('09:00'))->assertCreated();
    expect(appointmentMails($without['tenant'], 'cita_creada'))->toBeEmpty();
})->group('T-116', 'CA-47.6', 'RN-52', 'RF-155');

it('rejects overlapping appointments of the same patient with different dentists', function () {
    $scenario = bookingScenario();
    $colleague = User::factory()->for($scenario['tenant'])->create(['role' => 'dentist']);
    WorkSchedule::factory()->create(['tenant_id' => $scenario['tenant']->id, 'dentist_id' => $colleague->id, 'weekday' => 1, 'start_time' => '09:00', 'end_time' => '13:00']);
    book($scenario, at('09:00'))->assertCreated();

    book($scenario, at('09:15'), ['dentist_id' => $colleague->uuid])
        ->assertConflict()
        ->assertJsonPath('rule', 'RN-74');
})->group('T-117', 'RN-74', 'FE-5');

it('keeps a confirmed appointment confirmed when rescheduling and sends a new confirmation link', function () {
    $scenario = bookingScenario();
    $id = book($scenario, at('09:00'))->assertCreated()->json('data.id');
    $this->postJson("/api/v1/appointments/{$id}/confirm")->assertOk()
        ->assertJsonPath('data.status', 'confirmada')
        ->assertJsonPath('data.confirmation_channel', 'telefono');

    $this->postJson("/api/v1/appointments/{$id}/reschedule", ['starts_at' => at('11:00')], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmada')
        ->assertJsonPath('data.reconfirmation_pending', true)
        ->assertJsonPath('data.confirmed_at', null)
        ->assertJsonPath('data.starts_at', '2026-11-02T16:00:00+00:00')
        ->assertJsonPath('data.ends_at', '2026-11-02T16:30:00+00:00');

    $mails = appointmentMails($scenario['tenant'], 'cita_modificada');
    expect($mails)->toHaveCount(1)
        ->and($mails[0]->payload['data']['time'])->toBe('11:00')
        ->and($mails[0]->payload['links']['confirm'])->toContain('/confirmar/');
    TenantContext::run($scenario['tenant'], fn () => expect(AuditLog::query()->where('action', 'appointment.rescheduled')->count())->toBe(1));
})->group('RF-149', 'CUS-48');

it('cancels with a reason and rejects changes to a cancelled appointment', function () {
    $scenario = bookingScenario();
    $id = book($scenario, at('09:00'))->assertCreated()->json('data.id');

    $this->postJson("/api/v1/appointments/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors('cancel_reason');
    $this->postJson("/api/v1/appointments/{$id}/cancel", ['cancel_reason' => 'clinica', 'cancel_detail' => 'Odontóloga con descanso médico'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelada')
        ->assertJsonPath('data.cancel_reason', 'clinica');
    expect(appointmentMails($scenario['tenant'], 'cita_cancelada'))->toHaveCount(1);

    $this->postJson("/api/v1/appointments/{$id}/cancel", ['cancel_reason' => 'paciente'])->assertConflict();
    $this->postJson("/api/v1/appointments/{$id}/confirm")->assertConflict();
    $this->postJson("/api/v1/appointments/{$id}/reschedule", ['starts_at' => at('11:00')], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
})->group('RF-149', 'CUS-48');

it('confirms by the emailed link once and without a session', function () {
    $scenario = bookingScenario();
    $id = book($scenario, at('09:00'))->assertCreated()->json('data.id');
    $appointment = TenantContext::run($scenario['tenant'], fn () => Appointment::query()->where('uuid', $id)->sole());
    ['token' => $token] = TenantContext::run($scenario['tenant'], fn () => app(OneTimeTokenService::class)->issue($appointment, TokenPurpose::AppointmentConfirmation, $appointment->starts_at));
    app('auth')->forgetGuards();

    $this->postJson("/api/v1/public/appointment-confirmations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmada')
        ->assertJsonPath('data.dentist', 'Dra. Ana Torres')
        ->assertJsonPath('data.clinic', $scenario['tenant']->name)
        ->assertJsonMissingPath('data.patient');
    TenantContext::run($scenario['tenant'], fn () => expect(Appointment::query()->sole())
        ->confirmation_channel->toBe('enlace')
        ->status->toBe('confirmada'));

    // Un enlace usado dos veces no tiene efecto la segunda vez (RF-150).
    $this->postJson("/api/v1/public/appointment-confirmations/{$token}")->assertNotFound();
    $this->postJson('/api/v1/public/appointment-confirmations/no-existe')->assertNotFound();
})->group('RF-150', 'CUS-49', 'RNF-112');
