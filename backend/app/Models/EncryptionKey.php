<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Se crea únicamente vía TenantEncryption::generateKeyFor() al dar de alta una clínica
 * (sin factory propia: tenant_id es UNIQUE y TenantFactory ya genera la clave).
 */
#[Fillable(['key_ciphertext', 'is_active'])]
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
            'rotated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
