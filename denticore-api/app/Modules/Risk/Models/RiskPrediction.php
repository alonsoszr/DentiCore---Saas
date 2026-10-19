<?php

namespace App\Modules\Risk\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\RiskPredictionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Predicción de riesgo de caries (SDD §2.11 `risk_predictions`, §2.14.3; CUS-55, CUS-56; RN-59,
 * RN-60, RN-62, RN-63, RF-167, RF-168). Inmutable salvo `status` (vigente → reemplazada | vencida);
 * una sola `vigente` por paciente y `expires_at` = `predicted_at` + 12 meses.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $risk_variable_set_id
 * @property int $model_version_id
 * @property string $probability
 * @property string $level
 * @property string $confidence
 * @property string $threshold_medium
 * @property string $threshold_high
 * @property string $base_value
 * @property list<array{feature: string, mean_abs_shap: float}> $explanation_global
 * @property list<array{feature: string, value: string|null, shap: float}> $explanation_individual
 * @property string $status
 * @property int $requested_by
 * @property Carbon $predicted_at
 * @property Carbon $expires_at
 * @property string $request_ref
 * @property int $latency_ms
 * @property Carbon $created_at
 * @property-read Patient $patient
 * @property-read RiskVariableSet $variableSet
 * @property-read ModelVersion $modelVersion
 * @property-read User $requestedBy
 * @property-read RiskAlert|null $alert
 */
#[UseFactory(RiskPredictionFactory::class)]
class RiskPrediction extends Model
{
    /** @use HasFactory<RiskPredictionFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'probability' => 'decimal:4',
            'confidence' => 'decimal:4',
            'threshold_medium' => 'decimal:3',
            'threshold_high' => 'decimal:3',
            'base_value' => 'decimal:6',
            'explanation_global' => 'array',
            'explanation_individual' => 'array',
            'predicted_at' => 'datetime',
            'expires_at' => 'datetime',
            'latency_ms' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** Predicción de más de 12 meses o calculada con un registro de variables reemplazado (RNF-148). */
    public function isStale(): bool
    {
        return $this->expires_at->isPast()
            || RiskVariableSet::query()
                ->where('patient_id', $this->patient_id)
                ->where('id', '>', $this->risk_variable_set_id)
                ->exists();
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<RiskVariableSet, $this>
     */
    public function variableSet(): BelongsTo
    {
        return $this->belongsTo(RiskVariableSet::class, 'risk_variable_set_id');
    }

    /**
     * @return BelongsTo<ModelVersion, $this>
     */
    public function modelVersion(): BelongsTo
    {
        return $this->belongsTo(ModelVersion::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasOne<RiskAlert, $this>
     */
    public function alert(): HasOne
    {
        return $this->hasOne(RiskAlert::class);
    }
}
