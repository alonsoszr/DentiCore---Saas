<?php

/*
 * Check-in de la cita (TASK-069; SDD §5.2, §5.7; CUS-50, CUS-25; RF-151, RF-067, RN-10, RN-50;
 * T-120). La clínica está en America/Lima; la cita es el lunes 02/11/2026 de 10:00 a 10:30.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Audit\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\ClinicalFixtures;

/** @return array{tenant: Tenant, dentist: User, appointment: Appointment} */
function checkInScenario(bool $withConsent = true): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'America/Lima']);
    test()->actingAsRole('receptionist', $tenant);
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);
    $patient = ClinicalFixtures::patient($tenant, $withConsent);
    $appointment = Appointment::factory()->create([
        'tenant_id' => $tenant->id, 'dentist_id' => $dentist->id, 'patient_id' => $patient->id,
        'starts_at' => Carbon::parse('2026-11-02 10:00', 'America/Lima')->utc(),
        'ends_at' => Carbon::parse('2026-11-02 10:30', 'America/Lima')->utc(),
    ]);

    return compact('tenant', 'dentist', 'appointment');
}

function checkIn(Appointment $appointment): TestResponse
{
    return test()->postJson("/api/v1/appointments/{$appointment->uuid}/check-in", [], ['Idempotency-Key' => (string) Str::uuid()]);
}

function atLima(string $time): void
{
    test()->travelTo(Carbon::parse("2026-11-02 {$time}", 'America/Lima'));
}

it('rejects the check-in 61 minutes before and opens the attention inside the window', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'appointment' => $appointment] = checkInScenario();

    atLima('08:59');
    checkIn($appointment)->assertUnprocessable()->assertJsonPath('rule', 'RN-50');

    atLima('09:00');
    $response = checkIn($appointment)->assertOk()
        ->assertJsonPath('data.status', 'en_atencion')
        ->assertJsonPath('consent_warning', null);

    TenantContext::run($tenant, function () use ($appointment, $dentist, $response) {
        $attention = Attention::query()->where('uuid', $response->json('attention.id'))->sole();
        expect($attention->appointment_id)->toBe($appointment->id)
            ->and($attention->dentist_id)->toBe($dentist->id)
            ->and($attention->status)->toBe('abierta')
            ->and($appointment->fresh()->checked_in_at)->not->toBeNull()
            ->and(AuditLog::query()->where('action', 'appointment.checked_in')->count())->toBe(1);
    });
})->group('T-120', 'RN-50', 'RF-151', 'CUS-50');

it('rejects the check-in after the end or on another day and of a cancelled appointment', function () {
    ['appointment' => $appointment, 'tenant' => $tenant] = checkInScenario();

    atLima('10:31');
    checkIn($appointment)->assertUnprocessable()->assertJsonPath('rule', 'RN-50');

    atLima('10:00');
    TenantContext::run($tenant, fn () => $appointment->forceFill(['status' => 'cancelada', 'cancel_reason' => 'paciente'])->save());
    checkIn($appointment)->assertConflict();
})->group('RN-50', 'RF-151');

it('opens the attention without consent and returns the warning', function () {
    ['appointment' => $appointment] = checkInScenario(withConsent: false);
    atLima('09:45');

    checkIn($appointment)->assertOk()
        ->assertJsonPath('data.status', 'en_atencion')
        ->assertJsonPath('consent_warning.rule', 'RN-10');
})->group('RN-10', 'RF-067', 'CUS-25');

it('leaves the appointment attended when the attention closes', function () {
    ['tenant' => $tenant, 'dentist' => $dentist, 'appointment' => $appointment] = checkInScenario();
    atLima('09:55');
    $attention = checkIn($appointment)->assertOk()->json('attention.id');

    $this->actingWithToken($dentist);
    $this->putJson("/api/v1/attentions/{$attention}/note", ['chief_complaint' => 'Dolor al masticar'])->assertOk();
    $this->postJson("/api/v1/attentions/{$attention}/diagnoses", ['cie10_code' => 'K02.1', 'type' => 'definitivo'])->assertCreated();
    $this->postJson("/api/v1/attentions/{$attention}/close", [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

    TenantContext::run($tenant, fn () => expect($appointment->fresh()->status)->toBe('atendida'));
})->group('CUS-50', 'CUS-26');

it('leaves the appointment attended when the attention is closed incomplete at the end of the day', function () {
    ['tenant' => $tenant, 'appointment' => $appointment] = checkInScenario();
    atLima('09:55');
    checkIn($appointment)->assertOk();

    $this->travelTo(Carbon::parse('2026-11-03 00:05', 'America/Lima'));
    $this->artisan('attentions:auto-close')->assertSuccessful();

    TenantContext::run($tenant, function () use ($appointment) {
        expect(Attention::query()->sole()->status)->toBe('cerrada_incompleta')
            ->and($appointment->fresh()->status)->toBe('atendida');
    });
})->group('CUS-27', 'CUS-50');
