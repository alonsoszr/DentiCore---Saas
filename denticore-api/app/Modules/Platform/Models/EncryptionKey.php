<?php

namespace App\Modules\Platform\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Clave de cifrado versionada de una clínica (SDD §2.3 `encryption_keys`, DD-04). Se crea
 * vía TenantEncryption::generateKeyFor() al dar de alta la clínica; sin factory propia.
 */
#[Fillable(['key_ciphertext', 'version', 'status', 'is_active'])]
#[Hidden(['key_ciphertext'])]
class EncryptionKey extends Model
{
    use BelongsToTenant;

    /**
     * Espeja el default de columna (is_active default true) a nivel de PHP: ver nota
     * equivalente en Tenant::$attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'rotated_at' => 'datetime',
            'retired_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
