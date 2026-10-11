<?php

/*
 * Tipos de cita (TASK-065; SDD §2.10, §4.3.8; CUS-45; RF-145, RN-47).
 */

use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Models\AppointmentType;

it('lets the clinic administrator create and edit appointment types', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);

    $id = $this->postJson('/api/v1/appointment-types', ['name' => 'Control', 'default_duration_min' => 30, 'color' => '#1D4ED8'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Control')
        ->assertJsonPath('data.default_duration_min', 30)
        ->assertJsonPath('data.portal_visible', false)
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $this->patchJson("/api/v1/appointment-types/{$id}", ['default_duration_min' => 45, 'portal_visible' => true, 'is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.default_duration_min', 45)
        ->assertJsonPath('data.portal_visible', true)
        ->assertJsonPath('data.is_active', false);

    $this->getJson('/api/v1/appointment-types')->assertOk()->assertJsonCount(1, 'data');
})->group('RF-145', 'CUS-45');

it('rejects durations out of range or not multiple of 5, invalid colors and repeated names', function (array $payload, string $field) {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);
    AppointmentType::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Control']);

    $this->postJson('/api/v1/appointment-types', ['name' => 'Urgencia', 'default_duration_min' => 30, 'color' => '#DC2626', ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    '12 minutes' => [['default_duration_min' => 12], 'default_duration_min'],
    '5 minutes' => [['default_duration_min' => 5], 'default_duration_min'],
    '245 minutes' => [['default_duration_min' => 245], 'default_duration_min'],
    'color' => [['color' => 'azul'], 'color'],
    'repeated name' => [['name' => 'Control'], 'name'],
])->group('RF-145', 'RN-47');

it('allows the same name in another clinic', function () {
    AppointmentType::factory()->create(['name' => 'Control']);
    $this->actingAsRole('clinic_admin', Tenant::factory()->create());

    $this->postJson('/api/v1/appointment-types', ['name' => 'Control', 'default_duration_min' => 30, 'color' => '#1D4ED8'])->assertCreated();
})->group('RF-145', 'RNF-101');
