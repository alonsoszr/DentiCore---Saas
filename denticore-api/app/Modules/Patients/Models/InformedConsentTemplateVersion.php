<?php

namespace App\Modules\Patients\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versión de una plantilla de consentimiento informado (SDD §2.5 `informed_consent_template_versions`;
 * RF-072). Inmutable: un disparador rechaza cualquier UPDATE o DELETE.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $informed_consent_template_id
 * @property int $version
 * @property string $body
 * @property string $body_sha256
 * @property int $created_by
 * @property-read InformedConsentTemplate $template
 */
class InformedConsentTemplateVersion extends Model
{
    use BelongsToTenant;

    /** La tabla solo tiene `created_at` (con `DEFAULT now()`), sin `updated_at`. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InformedConsentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(InformedConsentTemplate::class, 'informed_consent_template_id');
    }
}
