<?php

namespace App\Support\Files;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Archivo guardado en el almacenamiento S3 (SDD §2.5 `stored_files`, DI-16). Solo un archivo
 * `limpio` se entrega (RNF-103).
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property string $scan_status
 * @property int|null $uploaded_by
 */
#[Fillable(['disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'scan_status', 'uploaded_by'])]
class StoredFile extends Model
{
    use BelongsToTenant, HasUuid;

    public const MAX_BYTES = 10_485_760;

    public function isDeliverable(): bool
    {
        return $this->scan_status === 'limpio';
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
        return ['size_bytes' => 'integer'];
    }
}
