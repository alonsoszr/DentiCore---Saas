<?php

namespace Database\Factories;

use App\Models\EncryptionKey;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EncryptionKey>
 */
class EncryptionKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'key_ciphertext' => fake()->sha256(),
            'is_active' => true,
        ];
    }
}
