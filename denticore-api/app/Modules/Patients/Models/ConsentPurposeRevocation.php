<?php

namespace App\Modules\Patients\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Revocación de una finalidad del consentimiento (SDD §2.5 `consent_purpose_revocations`, BTi;
 * RN-14, RF-068, RF-069). Solo se inserta: un disparador rechaza UPDATE y DELETE. La crea
 * ConsentRevocationService con `forceFill`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $consent_id
 * @property string $purpose
 * @property string $channel
 * @property int|null $revoked_by_user_id
 * @property string|null $reason
 * @property Carbon $created_at
 */
class ConsentPurposeRevocation extends Model
{
    use BelongsToTenant, HasUuid;

    public const UPDATED_AT = null;
}
