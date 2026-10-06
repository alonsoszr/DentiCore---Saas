<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Policies\ConsentPolicy;
use App\Support\Database\HasUuid;
use App\Support\Files\GeneratedDocument;
use App\Support\Files\StoredFile;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Consentimiento de datos (SDD §2.5 `consents`; RN-10, RN-11, RN-12, RN-15, DD-28, DD-46).
 * Inmutable salvo su estado: un disparador rechaza cualquier otro cambio, así que se crea con
 * `forceFill` desde ConsentService y no tiene atributos asignables.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $consent_template_version
 * @property bool $purpose_care
 * @property bool $purpose_notifications
 * @property bool $purpose_ai
 * @property bool $purpose_risk
 * @property bool $purpose_surveys
 * @property string $granted_by
 * @property int|null $legal_representative_id
 * @property string $channel
 * @property string $rendered_text
 * @property string $text_sha256
 * @property int|null $scanned_file_id
 * @property Carbon $granted_at
 * @property string|null $ip_address
 * @property int|null $assisted_by
 * @property string $evidence_hmac
 * @property string $status
 * @property Carbon|null $superseded_at
 * @property Carbon|null $revoked_at
 * @property int|null $certificate_document_id
 * @property-read Patient $patient
 * @property-read LegalRepresentative|null $legalRepresentative
 * @property-read GeneratedDocument|null $certificate
 * @property-read StoredFile|null $scannedFile
 * @property-read User|null $assistant
 */
#[UsePolicy(ConsentPolicy::class)]
class Consent extends Model
{
    use BelongsToTenant, HasUuid;

    /** Finalidad de RN-11 (nombre de `consent:<finalidad>` y de las revocaciones) → columna. */
    public const PURPOSES = [
        'atencion' => 'purpose_care',
        'notificaciones' => 'purpose_notifications',
        'ia' => 'purpose_ai',
        'prediccion' => 'purpose_risk',
        'encuestas' => 'purpose_surveys',
    ];

    protected function casts(): array
    {
        return [
            'purpose_care' => 'boolean',
            'purpose_notifications' => 'boolean',
            'purpose_ai' => 'boolean',
            'purpose_risk' => 'boolean',
            'purpose_surveys' => 'boolean',
            'granted_at' => 'datetime',
            'superseded_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Datos sellados con `evidence_hmac` (DD-46): lo que se otorgó, quién, cómo, cuándo y sobre
     * qué texto.
     *
     * @return array<string, mixed>
     */
    public function evidencePayload(): array
    {
        return [
            'consent' => $this->uuid,
            'patient' => $this->patient->uuid,
            'template_version' => $this->consent_template_version,
            'purposes' => collect(self::PURPOSES)->map(fn (string $column) => (bool) $this->getAttribute($column))->all(),
            'granted_by' => $this->granted_by,
            'representative' => $this->legalRepresentative?->uuid,
            'channel' => $this->channel,
            'text_sha256' => $this->text_sha256,
            'granted_at' => $this->granted_at->toIso8601ZuluString(),
            'assisted_by' => $this->assistant?->uuid,
        ];
    }

    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
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
     * @return BelongsTo<LegalRepresentative, $this>
     */
    public function legalRepresentative(): BelongsTo
    {
        return $this->belongsTo(LegalRepresentative::class);
    }

    /**
     * @return BelongsTo<GeneratedDocument, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class, 'certificate_document_id');
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function scannedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'scanned_file_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assisted_by');
    }
}
