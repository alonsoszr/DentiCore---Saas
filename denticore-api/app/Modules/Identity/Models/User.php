<?php

namespace App\Modules\Identity\Models;

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * NOTA: User deliberadamente NO usa BelongsToTenant/TenantScope. Sanctum resuelve el
 * usuario dueño de un token ANTES de que exista un tenant activo en el contenedor (de
 * hecho, ResolveTenant resuelve la clínica A PARTIR de este usuario ya autenticado; SDD
 * §1.6.1, excepción `users` de DD-03), así que un Global Scope que deniega por defecto sin tenant activo
 * rompería la autenticación por completo. tenant_id se mantiene como columna (no
 * fillable, ver #[Fillable] abajo) y se asigna explícitamente en el servicio que crea
 * el usuario, nunca desde el payload del cliente.
 *
 * El estado de la cuenta es `status` (SDD §2.4; DI-03).
 *
 * @property string|null $password
 * @property string $status
 * @property bool $is_data_officer
 * @property string|null $cop_number
 * @property string|null $specialty
 * @property string|null $rne_number
 * @property string|null $two_factor_secret
 * @property Carbon|null $two_factor_confirmed_at
 * @property bool $two_factor_reset_required
 * @property int $failed_login_count
 * @property Carbon|null $locked_until
 * @property Carbon|null $last_login_at
 * @property Carbon|null $password_changed_at
 * @property Carbon|null $deactivated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'status', 'cop_number', 'specialty', 'rne_number'])]
#[Hidden(['password', 'two_factor_secret'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable;

    /**
     * Roles que pertenecen a una clínica (tenant_id NOT NULL). super_admin queda fuera:
     * es el único rol de plataforma (SDD §3.1, §3.7).
     *
     * @var list<string>
     */
    public const TENANT_ROLES = ['clinic_admin', 'dentist', 'receptionist', 'patient'];

    /**
     * Defaults de columna espejados en PHP (ver Tenant::$attributes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_data_officer' => false,
        'two_factor_reset_required' => false,
        'failed_login_count' => 0,
    ];

    /**
     * Un usuario nuevo sin contraseña nace `pendiente_activacion` (DD-22); con contraseña,
     * `activo`.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->status ??= $user->password === null ? 'pendiente_activacion' : 'activo';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_data_officer' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_reset_required' => 'boolean',
            'failed_login_count' => 'integer',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * SDD §2.4: el correo se guarda en minúsculas (RN-05).
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Ficha vinculada a una cuenta de portal (role='patient'). Sin Global Scope porque se
     * resuelve también en el login, antes de que haya tenant activo. El filtro por
     * user_id (UNIQUE) ya la limita a la ficha propia, y PatientService solo permite
     * vincular cuentas de la misma clínica.
     *
     * @return HasOne<Patient, $this>
     */
    public function patient(): HasOne
    {
        return $this->hasOne(Patient::class)->withoutGlobalScope(TenantScope::class);
    }
}
