<?php

namespace Database\Factories;

use App\Modules\Odontogram\Models\FindingCatalog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Hallazgos sintéticos para las pruebas (RES-08). No reproducen el anexo de la NTS 188: la semilla
 * real llega en TASK-043b.
 *
 * @extends Factory<FindingCatalog>
 */
class FindingCatalogFactory extends Factory
{
    /**
     * @var class-string<FindingCatalog>
     */
    protected $model = FindingCatalog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PRUEBA_'.fake()->unique()->numerify('####'),
            'name' => 'Hallazgo de prueba '.fake()->unique()->numerify('###'),
            'acronym' => strtoupper(fake()->lexify('??')),
            'level' => 'superficie',
            'dentition' => 'ambas',
            'introduced_in_version' => '1.0',
            'retired_in_version' => null,
            'is_active' => true,
            'display_order' => fake()->numberBetween(1, 999),
        ];
    }

    public function level(string $level): static
    {
        return $this->state(['level' => $level]);
    }

    public function dentition(string $dentition): static
    {
        return $this->state(['dentition' => $dentition]);
    }
}
