<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\HasUuid;
use App\Support\Encryption\TenantEncrypted;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Representante legal de un paciente (SDD §2.5 `legal_representatives`; RN-12, RF-060, DD-13).
 * Documento y teléfono cifrados con la clave de la clínica; `document_hash` es el índice ciego
 * de `TIPO:NUMERO`. Vigente mientras `valid_until` sea nulo.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int|null $representative_patient_id
 * @property string $document_type
 * @property string $document_number
 * @property string $document_hash
 * @property string $first_name
 * @property string $last_name
 * @property string $relationship
 * @property string $phone
 * @property string|null $email
 * @property int|null $user_id
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until
 * @property string|null $ended_reason
 * @property-read Patient $patient
 */
#[Fillable(['document_type', 'document_number', 'first_name', 'last_name', 'relationship', 'phone', 'email', 'valid_from'])]
#[Hidden(['document_hash'])]
class LegalRepresentative extends Model
{
    use BelongsToTenant, HasUuid;

    protected function casts(): array
    {
        return [
            'document_number' => TenantEncrypted::class,
            'phone' => TenantEncrypted::class,
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /**
     * La auditoría registra el paciente representado (SDD §2.12).
     */
    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
    }

    public function isCurrent(): bool
    {
        return $this->valid_until === null;
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
