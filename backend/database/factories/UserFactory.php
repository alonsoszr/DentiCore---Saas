<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => fake()->randomElement(['clinic_admin', 'dentist', 'receptionist', 'patient']),
            'is_active' => true,
        ];
    }

    /**
     * Usuario de plataforma sin clínica asociada (technical_specs.md §2.3 punto 5).
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);
    }
}
