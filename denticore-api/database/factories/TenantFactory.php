<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\RucValidator;
use App\Modules\Platform\Services\TenantService;
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

        // RUC sintético de persona jurídica con dígito verificador válido (RF-014).
        $rucPrefix = '20'.fake()->unique()->numerify('########');

        return [
            'name' => $name,
            'legal_name' => "{$name} S.A.C.",
            'ruc' => $rucPrefix.RucValidator::checkDigit($rucPrefix),
            'address' => fake()->streetAddress(),
            // SDD §2.3: slug de 3 a 50 caracteres que no termina en guion.
            'slug' => rtrim(str($name)->slug()->limit(50, '')->toString(), '-'),
            'subscription_plan' => fake()->randomElement(['basic', 'pro', 'enterprise']),
            'status' => 'activa',
            'settings' => null,
        ];
    }

    /**
     * Igual que en el alta real (TenantService::create), toda clínica nace con su clave de
     * cifrado, sus parámetros y sus secuencias de documentos.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant): void {
            app(TenantService::class)->provision($tenant);
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
