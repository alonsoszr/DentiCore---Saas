<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Services\Encryption\TenantEncryption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => str($name)->slug(),
            'subscription_plan' => fake()->randomElement(['basic', 'pro', 'enterprise']),
            'status' => 'active',
            'settings' => null,
        ];
    }

    /**
     * Igual que en el alta real (TenantService::create), toda clínica nace con su clave
     * de cifrado.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            app(TenantEncryption::class)->generateKeyFor($tenant);
        });
    }
}
