<?php

namespace App\Support\Files;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Documento generado de forma asíncrona (SDD §2.12 `generated_documents`, DI-16, DD-18):
 * presupuestos, recibos, constancias, copias de HC, exportaciones.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $documentable_type
 * @property int $documentable_id
 * @property string $kind
 * @property string $status
 * @property int $attempts
 * @property int|null $stored_file_id
 * @property int|null $requested_by
 * @property CarbonImmutable|null $completed_at
 * @property string|null $error
 * @property-read StoredFile|null $storedFile
 */
#[Fillable(['documentable_type', 'documentable_id', 'kind', 'status', 'requested_by'])]
class GeneratedDocument extends Model
{
    use BelongsToTenant, HasUuid;

    public const MAX_ATTEMPTS = 3;

    /**
     * @return MorphTo<Model, $this>
     */
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<StoredFile, $this>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
