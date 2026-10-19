<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Files\GeneratedDocument;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Solicitud ARCO (SDD §2.12 `arco_requests`; CUS-63, CUS-64; RF-182, RN-69, RN-81, DD-26). La crea
 * y la atiende ArcoRequestService, por eso no tiene atributos asignables.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property string $type
 * @property string $requested_by
 * @property int|null $legal_representative_id
 * @property string $identity_verification
 * @property string $channel
 * @property string $description
 * @property string $status
 * @property Carbon $received_at
 * @property Carbon $due_date
 * @property Carbon|null $resolved_at
 * @property bool|null $within_deadline
 * @property string|null $response
 * @property string|null $denial_reason
 * @property int|null $handled_by
 * @property int|null $assigned_dentist_id
 * @property string|null $correction_entry_uuid
 * @property int|null $response_document_id
 * @property Carbon|null $reminder_sent_at
 * @property-read Patient $patient
 * @property-read LegalRepresentative|null $legalRepresentative
 * @property-read User|null $handler
 * @property-read GeneratedDocument|null $responseDocument
 */
class ArcoRequest extends Model
{
    use BelongsToTenant, HasUuid;

    public const TYPES = ['acceso', 'rectificacion', 'cancelacion', 'oposicion', 'portabilidad'];

    /** RN-69: plazo interno de respuesta en días hábiles (DD-26). */
    public const DEADLINE_BUSINESS_DAYS = 10;

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'due_date' => 'date',
            'resolved_at' => 'datetime',
            'within_deadline' => 'boolean',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * La auditoría registra el paciente de la solicitud (SDD §2.12).
     */
    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<LegalRepresentative, $this>
     */
    public function legalRepresentative(): BelongsTo
    {
        return $this->belongsTo(LegalRepresentative::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function responseDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'response_document_id');
    }

    /**
     * @return HasMany<ArcoRequestFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ArcoRequestFile::class);
    }
}
