<?php

/*
 * Fin de la representación y del acceso del representante al portal (TASK-085; RN-13, RF-061,
 * SDD §3.3).
 */

use App\Modules\Platform\Models\Tenant;
use Illuminate\Support\Carbon;
use Tests\Support\PortalFixtures;

it('ends representation and portal access on the 18th birthday', function () {
    $tenant = Tenant::factory()->create();
    $this->travelTo(Carbon::parse('2026-10-05 12:00:00', 'America/Lima'));
    $minor = PortalFixtures::minor($tenant, '2008-10-06');
    $representative = PortalFixtures::representative($tenant, $minor);
    $this->actingWithToken($representative);

    // La víspera del cumpleaños 18 el representante sigue viendo al paciente.
    $this->artisan('representations:end-at-majority')->assertSuccessful();
    $this->getJson('/api/v1/portal/subjects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $minor->uuid)
        ->assertJsonPath('data.0.relationship', 'representado');

    // El día en que cumple 18 años la representación termina y el portal deja de listarlo.
    $this->travelTo(Carbon::parse('2026-10-06 00:05:00', 'America/Lima'));
    $this->artisan('representations:end-at-majority')->assertSuccessful();

    expect(PortalFixtures::representation($tenant, $minor)->ended_reason)->toBe('mayoria_de_edad');
    $this->getJson('/api/v1/portal/subjects')->assertOk()->assertJsonCount(0, 'data');
})->group('T-043', 'RN-13', 'RF-061');
