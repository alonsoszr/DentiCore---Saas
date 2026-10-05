<?php

/*
 * T-014 (SDD §6.3.1; RN-01, RF-001): tenant_id nunca llega desde la solicitud.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

it('ignores a tenant_id injected in any write payload', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);

    $this->actingWithToken($admin)->postJson('/api/v1/patients', [
        'tenant_id' => $other->id,
        'document_id' => '70000001',
        'first_name' => 'Ana',
        'last_name' => 'Quispe',
        'birth_date' => '1990-01-01',
    ])->assertCreated();

    $this->actingWithToken($admin)->postJson('/api/v1/users', [
        'tenant_id' => $other->id,
        'name' => 'Luis',
        'email' => 'luis@clinica.test',
        'password' => 'password123',
        'role' => 'receptionist',
    ])->assertCreated();

    $user = User::query()->where('email', 'luis@clinica.test')->sole();
    $this->actingWithToken($admin)->patchJson("/api/v1/users/{$user->uuid}", [
        'tenant_id' => $other->id,
        'name' => 'Luis Ramos',
    ])->assertOk();

    expect(TenantContext::run($tenant, fn () => Patient::query()->sole()->tenant_id))->toBe($tenant->id)
        ->and(TenantContext::run($other, fn () => Patient::query()->count()))->toBe(0)
        ->and($user->fresh()->tenant_id)->toBe($tenant->id)
        ->and($user->fresh()->name)->toBe('Luis Ramos');
})->group('RN-01', 'RF-001');
