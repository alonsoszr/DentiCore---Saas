<?php

/*
 * Historial por pieza en el portal (TASK-088; CUS-24; RF-081, DI-13): las entradas de la pieza
 * con autor y COP, fecha y origen, sin la nota clínica.
 */

use App\Modules\Platform\Models\Tenant;
use Tests\Support\PortalFixtures;

it('shows the history of a tooth without the clinical note', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant);
    $entry = PortalFixtures::recordedFinding($tenant, $patient);
    $this->actingWithToken($user);

    $this->getJson("/api/v1/portal/patients/{$patient->uuid}/teeth/36/history")
        ->assertOk()
        ->assertJsonPath('data.0.id', $entry)
        ->assertJsonPath('data.0.finding.code', 'PRUEBA_CARIES')
        ->assertJsonPath('data.0.state.code', 'ACTIVA')
        ->assertJsonPath('data.0.origin', 'manual')
        ->assertJsonPath('data.0.author.name', 'Dra. Pérez')
        ->assertJsonPath('data.0.author.cop', '12345')
        ->assertJsonMissingPath('data.0.note')
        ->assertJsonMissingPath('data.0.correction_reason');
    $this->getJson("/api/v1/portal/patients/{$patient->uuid}/teeth/19/history")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tooth');
})->group('CUS-24', 'RF-081', 'DI-13');

it('returns 404 for the teeth of another patient', function () {
    $tenant = Tenant::factory()->create();
    [$user] = PortalFixtures::holder($tenant);
    [, $other] = PortalFixtures::holder($tenant);
    PortalFixtures::recordedFinding($tenant, $other);
    $this->actingWithToken($user);

    $this->getJson("/api/v1/portal/patients/{$other->uuid}/teeth/36/history")->assertNotFound();
})->group('CUS-24', 'RN-03');
