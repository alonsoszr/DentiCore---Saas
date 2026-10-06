<?php

namespace Database\Factories;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Estados sintéticos de un hallazgo de prueba (RES-08).
 *
 * @extends Factory<FindingState>
 */
class FindingStateFactory extends Factory
{
    /**
     * @var class-string<FindingState>
     */
    protected $model = FindingState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'finding_id' => FindingCatalog::factory(),
            'code' => 'ESTADO_'.fake()->unique()->numerify('####'),
            'name' => 'Estado de prueba',
            'color' => fake()->randomElement(['azul', 'rojo']),
            'acronym' => null,
            'is_active' => true,
        ];
    }
}
