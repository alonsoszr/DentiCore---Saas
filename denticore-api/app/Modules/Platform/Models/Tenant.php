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
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Clínica (SDD §2.3 `tenants`, raíz del aislamiento). TASK-021 expandió el esquema heredado
 * (RUC, razón social, plan como FK, estados en español) y TASK-038 retiró las columnas
 * heredadas `subscription_plan` y `settings`.
 *
 * @property string $status
 * @property string $legal_name
 * @property string $ruc
 * @property string $address
 * @property string|null $phone
 * @property string|null $contact_email
 * @property int|null $logo_file_id
 * @property int $subscription_plan_id
 * @property string|null $status_reason
 * @property Carbon|null $suspended_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $purged_at
 * @property string $timezone
 */
#[Fillable([
    'name', 'legal_name', 'ruc', 'slug', 'address', 'phone', 'contact_email', 'subscription_plan_id',
    'status', 'timezone',
])]
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuid;

    /**
     * Espeja los defaults de columna a nivel de PHP: los defaults de BD no se reflejan en el
     * modelo en memoria inmediatamente tras create(), solo en la fila real (mismo problema que
     * motivó HasUuid).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'activa',
        'timezone' => 'America/Lima',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubscriptionPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * @return HasOne<ClinicSetting, $this>
     */
    public function clinicSettings(): HasOne
    {
        return $this->hasOne(ClinicSetting::class);
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
