<?php

/*
 * Citas en el portal (TASK-087; CUS-48, CUS-49, CUS-77; RF-149, RF-150, RN-49): el paciente ve
 * sus citas, las confirma y las reprograma o cancela hasta `portal_cancel_hours` antes. El reloj
 * se fija el lunes 02/11/2026 a las 10:00 de Lima; el plazo por defecto es de 24 horas.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\PortalFixtures;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-02 10:00:00', 'America/Lima')));

function portalAppointmentAction(string $appointment, string $action, array $payload = []): TestResponse
{
    return test()->postJson("/api/v1/portal/appointments/{$appointment}/{$action}", $payload, ['Idempotency-Key' => (string) Str::uuid()]);
}

it('rejects a portal cancellation 23 hours before with a 24 hour limit', function () {
    $clinic = PortalFixtures::bookingClinic();
    $soon = PortalFixtures::clinicAppointment($clinic, '2026-11-03 09:00');
    $later = PortalFixtures::clinicAppointment($clinic, '2026-11-03 11:00');
    $this->actingWithToken($clinic['user']);

    portalAppointmentAction($soon->uuid, 'cancel')->assertUnprocessable()->assertJsonPath('rule', 'RN-49');
    portalAppointmentAction($soon->uuid, 'reschedule', ['starts_at' => Carbon::parse('2026-11-03 15:00', 'America/Lima')->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonPath('rule', 'RN-49');

    // A 25 horas sí: la cancelación del portal queda con el motivo «paciente».
    portalAppointmentAction($later->uuid, 'cancel', ['cancel_detail' => 'Viaje'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelada')
        ->assertJsonPath('data.cancel_reason', 'paciente')
        ->assertJsonPath('data.cancel_detail', 'Viaje');
})->group('T-119', 'RN-49', 'RF-149');

it('reschedules from the portal ahead of the limit', function () {
    $clinic = PortalFixtures::bookingClinic();
    $appointment = PortalFixtures::clinicAppointment($clinic, '2026-11-03 11:00');
    $this->actingWithToken($clinic['user']);

    portalAppointmentAction($appointment->uuid, 'reschedule', ['starts_at' => Carbon::parse('2026-11-03 15:00', 'America/Lima')->toIso8601String()])
        ->assertOk()
        ->assertJsonPath('data.starts_at', Carbon::parse('2026-11-03 15:00', 'America/Lima')->utc()->toIso8601String());
})->group('CUS-48', 'RF-149');

it('confirms an appointment from the portal and lists the appointments of the patient', function () {
    $clinic = PortalFixtures::bookingClinic();
    $appointment = PortalFixtures::clinicAppointment($clinic, '2026-11-03 09:00');
    $this->actingWithToken($clinic['user']);

    portalAppointmentAction($appointment->uuid, 'confirm')
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmada')
        ->assertJsonPath('data.confirmation_channel', 'portal');

    $this->getJson("/api/v1/portal/patients/{$clinic['patient']->uuid}/appointments")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $appointment->uuid)
        ->assertJsonPath('data.0.dentist.name', 'Dra. Ana Torres');
})->group('CUS-49', 'CUS-77', 'RF-150');

it('returns 404 for the appointments of another patient', function () {
    $clinic = PortalFixtures::bookingClinic();
    $appointment = PortalFixtures::clinicAppointment($clinic, '2026-11-04 09:00');
    [$stranger] = PortalFixtures::holder($clinic['tenant']);
    $this->actingWithToken($stranger);

    portalAppointmentAction($appointment->uuid, 'cancel')->assertNotFound();
    portalAppointmentAction($appointment->uuid, 'confirm')->assertNotFound();
    $this->getJson("/api/v1/portal/patients/{$clinic['patient']->uuid}/appointments")->assertNotFound();
})->group('RN-03', 'CUS-48');
