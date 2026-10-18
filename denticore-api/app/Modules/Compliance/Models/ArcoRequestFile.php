<?php

namespace App\Modules\Compliance\Models;

use App\Support\Files\StoredFile;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Adjunto de una solicitud ARCO (SDD §2.12 `arco_request_files`; RF-182).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $arco_request_id
 * @property int $stored_file_id
 * @property Carbon $created_at
 * @property-read StoredFile $storedFile
 */
class ArcoRequestFile extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }
}
