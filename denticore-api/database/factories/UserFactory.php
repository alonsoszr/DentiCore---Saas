<?php

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @var class-string<User>
     */
    protected $model = User::class;

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
            // RN-75: todo odontólogo tiene número de COP (sintético, RES-08).
            'cop_number' => fn (array $attributes): ?string => $attributes['role'] === 'dentist' ? fake()->unique()->numerify('#####') : null,
        ];
    }

    /**
     * Usuario de plataforma sin clínica asociada (SDD §3.1, RN-04).
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => null,
            'role' => 'super_admin',
        ]);
    }
}
