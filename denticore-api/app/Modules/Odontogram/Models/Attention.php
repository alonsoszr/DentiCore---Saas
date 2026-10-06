<?php

namespace App\Modules\Odontogram\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AttentionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Atención odontológica (SDD §2.6 `attentions`; CUS-25 a CUS-27, RF-082, RN-77). Una sola
 * `abierta` por odontólogo y paciente (índice parcial `attentions_open_unique`).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $dentist_id
 * @property string $status
 * @property bool $is_first_attention
 * @property Carbon $opened_at
 * @property Carbon|null $clinical_started_at
 * @property Carbon|null $closed_at
 * @property bool $closed_by_system
 * @property int|null $signed_by
 * @property string|null $signer_cop
 * @property Carbon|null $signed_at
 * @property string|null $evidence_hmac
 * @property-read Patient $patient
 * @property-read User $dentist
 */
#[UseFactory(AttentionFactory::class)]
class Attention extends Model
{
    /** @use HasFactory<AttentionFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'is_first_attention' => 'boolean',
            'closed_by_system' => 'boolean',
            'opened_at' => 'datetime',
            'clinical_started_at' => 'datetime',
            'closed_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

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
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }
}
