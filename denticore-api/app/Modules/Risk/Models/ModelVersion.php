<?php

namespace App\Modules\Risk\Models;

use App\Support\Database\HasUuid;
use Database\Factories\ModelVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Versión del modelo de riesgo (SDD §2.11 `model_versions`; DD-12, DI-18, RN-84, RF-175). Tabla de
 * plataforma: Laravel decide la versión activa y el motor carga sus artefactos de
 * `models/<version>/`. Una sola `activa`, con AUC ≥ 0,75 y Brier informado (CHECK).
 *
 * @property int $id
 * @property string $uuid
 * @property string $version
 * @property Carbon $trained_at
 * @property string $dataset_description
 * @property string $dataset_hash
 * @property string $code_commit
 * @property int $seed
 * @property array<string, mixed> $hyperparameters
 * @property string $auc_validation
 * @property string|null $brier_score
 * @property string|null $ece
 * @property array<string, mixed>|null $subgroup_metrics
 * @property string $threshold_medium
 * @property string $threshold_high
 * @property int|null $model_card_file_id
 * @property string $status
 * @property Carbon|null $activated_at
 * @property int|null $activated_by
 */
#[UseFactory(ModelVersionFactory::class)]
class ModelVersion extends Model
{
    /** @use HasFactory<ModelVersionFactory> */
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'trained_at' => 'datetime',
            'seed' => 'integer',
            'hyperparameters' => 'array',
            'auc_validation' => 'decimal:4',
            'brier_score' => 'decimal:4',
            'ece' => 'decimal:4',
            'subgroup_metrics' => 'array',
            'threshold_medium' => 'decimal:3',
            'threshold_high' => 'decimal:3',
            'activated_at' => 'datetime',
        ];
    }

    /** Versión `activa`, o null si la plataforma aún no activó ninguna (SRS §11.14 FE-4). */
    public static function active(): ?self
    {
        return self::query()->where('status', 'activa')->first();
    }
}
