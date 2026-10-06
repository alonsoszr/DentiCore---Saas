<?php

namespace Tests;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    /**
     * Autentica un usuario del rol indicado (SDD §6.2). El personal y los pacientes
     * pertenecen a `$tenant` (o a una clínica nueva); super_admin no tiene clínica. El token
     * lleva la habilidad `full` de SDD §1.7.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function actingAsRole(string $role, ?Tenant $tenant = null, array $attributes = []): User
    {
        $factory = User::factory();

        $user = $role === 'super_admin'
            ? $factory->superAdmin()->create($attributes)
            : $factory->for($tenant ?? Tenant::factory()->create())->create(['role' => $role, ...$attributes]);

        Sanctum::actingAs($user, ['full']);

        return $user;
    }

    /**
     * Autentica a un usuario existente con un token de las habilidades indicadas (por defecto
     * `full`). Las rutas AUTH exigen habilidad (SDD §4.3.2), así que `actingAs($user, 'sanctum')`
     * sin token no basta.
     *
     * @param  list<string>  $abilities
     */
    protected function actingWithToken(User $user, array $abilities = ['full']): static
    {
        Sanctum::actingAs($user, $abilities);

        return $this;
    }
}
