<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\TenantEncryption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @var class-string<Tenant>
     */
    protected $model = Tenant::class;

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

    /**
     * Clínica con su primer clinic_admin (SDD §6.2: fábricas con clínica explícita).
     *
     * @param  array<string, mixed>  $attributes  Atributos del administrador.
     */
    public function withAdmin(array $attributes = []): static
    {
        return $this->afterCreating(function (Tenant $tenant) use ($attributes): void {
            User::factory()->for($tenant)->create(['role' => 'clinic_admin', ...$attributes]);
        });
    }
}
