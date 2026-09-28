<?php

namespace App\Modules\Platform\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Clínica (SDD §2.3 `tenants`, raíz del aislamiento). Esquema heredado de las fases 0–3;
 * TASK-021 lo expande (RUC, razón social, plan como FK, estados en español).
 */
#[Fillable(['name', 'slug', 'subscription_plan', 'status', 'settings'])]
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuid;

    /**
     * Espeja el default de columna (status default 'active') a nivel de PHP: los
     * defaults de BD no se reflejan en el modelo en memoria inmediatamente tras
     * create(), solo en la fila real (mismo problema que motivó HasUuid).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Patient, $this>
     */
    public function patients(): HasMany
    {
        return $this->hasMany(Patient::class);
    }

    /**
     * @return HasOne<EncryptionKey, $this>
     */
    public function encryptionKey(): HasOne
    {
        return $this->hasOne(EncryptionKey::class);
    }
}
