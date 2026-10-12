<?php

namespace App\Modules\Risk\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\RiskVariableSetFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conjunto de variables de riesgo de un paciente (SDD §2.11 `risk_variable_sets`, §2.14.2; CUS-54;
 * RF-161, RF-162, RF-164, RN-58, DI-20). Secciones `clinical` y `behavioral` (odontólogo) y
 * `sociodemographic` (recepción). Al usarse en una predicción queda bloqueado (`locked_at`) y un
 * cambio posterior crea otro conjunto con `supersedes_id`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property string $age_group
 * @property int $age_years
 * @property array<string, mixed> $clinical
 * @property array<string, mixed> $behavioral
 * @property array<string, mixed> $sociodemographic
 * @property bool $is_complete
 * @property int|null $clinical_captured_by
 * @property Carbon|null $clinical_captured_at
 * @property int|null $sociodemographic_captured_by
 * @property Carbon|null $sociodemographic_captured_at
 * @property int|null $supersedes_id
 * @property Carbon|null $locked_at
 * @property Carbon $created_at
 * @property-read Patient $patient
 * @property-read User|null $clinicalCapturedBy
 * @property-read User|null $sociodemographicCapturedBy
 * @property-read RiskVariableSet|null $supersedes
 */
#[UseFactory(RiskVariableSetFactory::class)]
class RiskVariableSet extends Model
{
    /** @use HasFactory<RiskVariableSetFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'age_years' => 'integer',
            'clinical' => 'array',
            'behavioral' => 'array',
            'sociodemographic' => 'array',
            'is_complete' => 'boolean',
            'clinical_captured_at' => 'datetime',
            'sociodemographic_captured_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function clinicalCapturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'clinical_captured_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sociodemographicCapturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sociodemographic_captured_by');
    }

    /**
     * @return BelongsTo<RiskVariableSet, $this>
     */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }
}
