<?php

namespace App\Modules\Risk\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Risk\Models\ModelVersion;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registro y activación de versiones del modelo (SDD §5.8.4; RN-84, RF-175, RNF-145, DI-18). En E1
 * los invoca el comando de fontanería `risk:register-model` (supuesto S-09); los endpoints de
 * CUS-61 llegan en E3.
 *
 * - Registrar copia los metadatos de `GET /v1/models` (métricas, `dataset_hash`, semilla,
 *   hiperparámetros y umbrales). Una versión que el motor no publica se rechaza.
 * - Activar exige AUC ≥ 0,75 y Brier informado (también el CHECK de la tabla) y que el motor
 *   tenga la versión; en una transacción la activa anterior pasa a `retirada`.
 */
class ModelVersionService
{
    public function __construct(private readonly RiskEngineClient $engine, private readonly AuditLogger $audit) {}

    /**
     * @throws BusinessRuleException|RiskEngineUnavailable
     */
    public function register(string $version): ModelVersion
    {
        $existing = ModelVersion::query()->where('version', $version)->first();
        if ($existing !== null) {
            return $existing;
        }

        $metadata = $this->published($version);
        $metrics = (array) ($metadata['metrics'] ?? []);
        $thresholds = (array) ($metadata['thresholds'] ?? []);

        $model = new ModelVersion;
        $model->forceFill([
            'version' => $version,
            'trained_at' => Carbon::parse((string) ($metadata['trained_at'] ?? 'now'))->utc(),
            'dataset_description' => sprintf(
                'Conjunto sintético %s de %s filas (RES-08). Un AUC sobre datos sintéticos no demuestra validez clínica (OUT-06).',
                (string) ($metadata['dataset_version'] ?? '—'),
                (string) ($metadata['dataset_rows'] ?? '—'),
            ),
            'dataset_hash' => (string) ($metadata['dataset_hash'] ?? ''),
            'code_commit' => mb_substr((string) ($metadata['code_commit'] ?? ''), 0, 40),
            'seed' => (int) ($metadata['seed'] ?? 0),
            'hyperparameters' => (array) ($metadata['hyperparameters'] ?? []),
            'auc_validation' => (float) ($metrics['auc_validation'] ?? 0),
            'brier_score' => isset($metrics['brier_score']) ? (float) $metrics['brier_score'] : null,
            'ece' => isset($metrics['ece']) ? (float) $metrics['ece'] : null,
            'subgroup_metrics' => $metadata['subgroup_metrics'] ?? null,
            'threshold_medium' => (float) ($thresholds['medium'] ?? 0.3),
            'threshold_high' => (float) ($thresholds['high'] ?? 0.6),
            'status' => 'registrada',
        ])->save();

        $this->audit->record(AuditEvent::ModelRegistered, $model, meta: ['version' => $version]);

        return $model;
    }

    /**
     * @throws BusinessRuleException|RiskEngineUnavailable
     */
    public function activate(ModelVersion $model, ?User $actor = null): ModelVersion
    {
        if ((float) $model->auc_validation < 0.75 || $model->brier_score === null) {
            throw new BusinessRuleException('RN-84', 'La versión no cumple AUC ≥ 0,75 con Brier informado.');
        }

        $this->published($model->version);

        DB::transaction(function () use ($model, $actor): void {
            ModelVersion::query()->where('status', 'activa')->whereKeyNot($model->id)->update(['status' => 'retirada']);
            $model->forceFill(['status' => 'activa', 'activated_at' => now()->utc(), 'activated_by' => $actor?->id])->save();
        });

        $this->audit->record(AuditEvent::ModelActivated, $model, meta: ['version' => $model->version], actor: $actor);

        return $model;
    }

    /**
     * Metadatos de la versión en el motor, o rechazo si no la publica (RN-84).
     *
     * @return array<string, mixed>
     *
     * @throws BusinessRuleException|RiskEngineUnavailable
     */
    private function published(string $version): array
    {
        foreach ($this->engine->models() as $metadata) {
            if (($metadata['version'] ?? null) === $version) {
                return $metadata;
            }
        }

        throw new BusinessRuleException('RN-84', "La versión {$version} no figura en GET /v1/models del motor.");
    }
}
