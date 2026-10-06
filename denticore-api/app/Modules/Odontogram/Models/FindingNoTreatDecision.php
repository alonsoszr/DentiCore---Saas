<?php

namespace App\Modules\Odontogram\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Decisión de no tratar un hallazgo rojo (SDD §2.6 `finding_no_treat_decisions`; CUS-34; RF-113,
 * RN-27): una por entrada del odontograma, referenciada por su uuid (tabla particionada, sin FK).
 * Inmutable; solo la fusión de pacientes cambia `patient_id` (DI-21).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $odontogram_entry_uuid
 * @property int $patient_id
 * @property string $reason
 * @property int $decided_by
 * @property Carbon $created_at
 * @property-read Patient $patient
 * @property-read User $decider
 */
class FindingNoTreatDecision extends Model
{
    use BelongsToTenant, HasUuid;

    public const UPDATED_AT = null;

    public function getRouteKeyName(): string
    {
        return 'uuid';
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
    }
}
