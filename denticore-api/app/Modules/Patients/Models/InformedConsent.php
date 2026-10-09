<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Policies\InformedConsentPolicy;
use App\Modules\Treatment\Models\PlanItem;
use App\Support\Database\HasUuid;
use App\Support\Files\StoredFile;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Consentimiento informado de un procedimiento (SDD §2.5 `informed_consents`; CUS-83, RF-073,
 * RF-074, RN-12, RN-76, DD-31, DD-46). Inmutable salvo su estado (status, used_at, revoked_at,
 * revocation_reason), igual que Consent: se crea con `forceFill` desde InformedConsentService.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $plan_item_id
 * @property int $template_version_id
 * @property string $rendered_text
 * @property string $text_sha256
 * @property string $signer
 * @property int|null $legal_representative_id
 * @property string $channel
 * @property int|null $scanned_file_id
 * @property int $informed_by
 * @property int $registered_by
 * @property Carbon $signed_at
 * @property string|null $ip_address
 * @property string $evidence_hmac
 * @property string $status
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property string|null $revocation_reason
 * @property-read Patient $patient
 * @property-read PlanItem $planItem
 * @property-read InformedConsentTemplateVersion $templateVersion
 * @property-read LegalRepresentative|null $legalRepresentative
 * @property-read StoredFile|null $scannedFile
 * @property-read User|null $informedBy
 * @property-read User|null $registeredBy
 */
#[UsePolicy(InformedConsentPolicy::class)]
class InformedConsent extends Model
{
    use BelongsToTenant, HasUuid;

    protected function casts(): array
    {
        return [
            'signed_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Datos sellados con `evidence_hmac` (DD-46): quién, cómo, cuándo y sobre qué texto se firmó.
     *
     * @return array<string, mixed>
     */
    public function evidencePayload(): array
    {
        return [
            'consent' => $this->uuid,
            'patient' => $this->patient->uuid,
            'procedure' => $this->planItem->procedure->uuid,
            'plan_item' => $this->planItem->uuid,
            'template_version' => $this->templateVersion->version,
            'signer' => $this->signer,
            'representative' => $this->legalRepresentative?->uuid,
            'channel' => $this->channel,
            'text_sha256' => $this->text_sha256,
            'signed_at' => $this->signed_at->toIso8601ZuluString(),
            'informed_by' => $this->informedBy->uuid,
            'registered_by' => $this->registeredBy->uuid,
        ];
    }

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
     * @return BelongsTo<PlanItem, $this>
     */
    public function planItem(): BelongsTo
    {
        return $this->belongsTo(PlanItem::class);
    }

    /**
     * @return BelongsTo<InformedConsentTemplateVersion, $this>
     */
    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(InformedConsentTemplateVersion::class, 'template_version_id');
    }

    /**
     * @return BelongsTo<LegalRepresentative, $this>
     */
    public function legalRepresentative(): BelongsTo
    {
        return $this->belongsTo(LegalRepresentative::class);
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function scannedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'scanned_file_id');
    }

    /**
     * Odontólogo que informó el procedimiento (RF-073).
     *
     * @return BelongsTo<User, $this>
     */
    public function informedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'informed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
