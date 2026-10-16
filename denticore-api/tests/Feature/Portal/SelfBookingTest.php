<?php

/*
 * Reserva desde el portal (TASK-087; CUS-46, CUS-47 FA-1; RF-146, RF-148, RN-48, CA-47.4): solo
 * tipos visibles en el portal, con 2 horas de anticipación y como máximo 2 citas activas
 * autoagendadas. El reloj se fija el lunes 02/11/2026 a las 10:00 de Lima.
 */

use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PortalFixtures;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-02 10:00:00', 'America/Lima')));

/** Hora local de Lima en ISO 8601 (lunes 02/11/2026 salvo que se indique otro día). */
function limaAt(string $time, string $date = '2026-11-02'): string
{
    return Carbon::parse("{$date} {$time}", 'America/Lima')->toIso8601String();
}

/** @param  array<string, mixed>  $clinic */
function portalBook(array $clinic, string $startsAt, array $overrides = []): TestResponse
{
    return test()->postJson("/api/v1/portal/patients/{$clinic['patient']->uuid}/appointments", [
        'appointment_type_id' => $clinic['type']->uuid,
        'dentist_id' => $clinic['dentist']->uuid,
        'starts_at' => $startsAt,
        ...$overrides,
    ], ['Idempotency-Key' => (string) Str::uuid()]);
}

it('rejects a portal booking less than 2 hours ahead and a third self booked appointment', function () {
    $clinic = PortalFixtures::bookingClinic();
    PortalFixtures::clinicAppointment($clinic, '2026-11-03 09:00');
    $this->actingWithToken($clinic['user']);

    // CA-47.4: a las 10:00 no se reserva a las 11:30 del mismo día.
    portalBook($clinic, limaAt('11:30'))->assertUnprocessable()->assertJsonPath('rule', 'RN-48');

    portalBook($clinic, limaAt('12:15'))
        ->assertCreated()
        ->assertJsonPath('data.origin', 'portal')
        ->assertJsonPath('data.status', 'programada');
    portalBook($clinic, limaAt('15:00'))->assertCreated();

    // La cita reservada por la clínica no cuenta; la tercera autoagendada sí se rechaza.
    portalBook($clinic, limaAt('16:00'))
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-48')
        ->assertJsonPath('detail', 'Ya tiene 2 citas activas reservadas por el portal.');

    expect(TenantContext::run($clinic['tenant'], fn () => Appointment::query()->where('origin', 'portal')->count()))->toBe(2);
})->group('T-114', 'CA-47.4', 'RN-48', 'RF-148');

it('books only the appointment types visible in the portal', function () {
    $clinic = PortalFixtures::bookingClinic();
    $this->actingWithToken($clinic['user']);

    portalBook($clinic, limaAt('14:00'), ['appointment_type_id' => $clinic['internalType']->uuid])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('appointment_type_id');
})->group('CUS-46', 'RF-148');

it('lists the free slots of the portal types from 2 hours ahead', function () {
    $clinic = PortalFixtures::bookingClinic();
    $this->actingWithToken($clinic['user']);

    $slots = $this->getJson('/api/v1/portal/availability?'.http_build_query([
        'appointment_type_id' => $clinic['type']->uuid,
        'patient_id' => $clinic['patient']->uuid,
        'from' => '2026-11-02',
        'to' => '2026-11-02',
    ]))->assertOk()->assertJsonPath('data.0.dentist.name', 'Dra. Ana Torres')->json('data.0.slots');

    expect(Carbon::parse($slots[0])->equalTo(Carbon::parse(limaAt('12:15'))))->toBeTrue();
    $this->getJson('/api/v1/portal/availability?'.http_build_query([
        'appointment_type_id' => $clinic['internalType']->uuid, 'from' => '2026-11-02', 'to' => '2026-11-02',
    ]))->assertUnprocessable()->assertJsonValidationErrors('appointment_type_id');
})->group('CUS-46', 'RF-146', 'RN-48');

it('forbids self booking when the clinic has not enabled it', function () {
    $clinic = PortalFixtures::bookingClinic();
    TenantContext::run($clinic['tenant'], fn () => ClinicSetting::query()->sole()->forceFill(['self_booking_enabled' => false])->save());
    $this->actingWithToken($clinic['user']);

    portalBook($clinic, limaAt('14:00'))->assertForbidden();
    $this->getJson('/api/v1/portal/availability?from=2026-11-02&to=2026-11-02&appointment_type_id='.$clinic['type']->uuid)->assertForbidden();
})->group('RF-148', 'RF-026');
