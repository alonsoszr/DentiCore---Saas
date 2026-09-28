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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * NOTA: User deliberadamente NO usa BelongsToTenant/TenantScope. Sanctum resuelve el
 * usuario dueño de un token ANTES de que exista un tenant activo en el contenedor (de
 * hecho, ResolveTenant resuelve la clínica A PARTIR de este usuario ya autenticado; SDD
 * §1.6.1, excepción `users` de DD-03), así que un Global Scope que deniega por defecto sin tenant activo
 * rompería la autenticación por completo. tenant_id se mantiene como columna (no
 * fillable, ver #[Fillable] abajo) y se asigna explícitamente en el servicio que crea
 * el usuario, nunca desde el payload del cliente.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password'])]
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
     * Espeja el default de columna (is_active default true) a nivel de PHP: ver nota
     * equivalente en Tenant::$attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
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
