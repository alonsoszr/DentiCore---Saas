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
}
