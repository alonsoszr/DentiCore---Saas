<?php

namespace Database\Factories;

use App\Modules\Risk\Models\ModelVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Versión registrada del modelo de riesgo con métricas que permiten activarla (RN-84). El estado
 * `active()` la deja activa.
 *
 * @extends Factory<ModelVersion>
 */
class ModelVersionFactory extends Factory
{
    /**
     * @var class-string<ModelVersion>
     */
    protected $model = ModelVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'version' => sprintf('1.%d.%d', fake()->unique()->numberBetween(0, 999), fake()->numberBetween(0, 9)),
            'trained_at' => now()->subDay(),
            'dataset_description' => 'Conjunto sintético (RES-08).',
            'dataset_hash' => hash('sha256', fake()->uuid()),
            'code_commit' => sha1(fake()->uuid()),
            'seed' => 20261005,
            'hyperparameters' => ['max_depth' => 4],
            'auc_validation' => '0.7800',
            'brier_score' => '0.1600',
            'ece' => '0.0200',
            'status' => 'registrada',
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => 'activa', 'activated_at' => now()]);
    }
}
