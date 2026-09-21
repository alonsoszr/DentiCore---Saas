<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * NOTA: User deliberadamente NO usa BelongsToTenant/TenantScope. Sanctum resuelve el
 * usuario dueño de un token ANTES de que exista un tenant activo en el contenedor (de
 * hecho, technical_specs.md §2.3 punto 3 resuelve el tenant A PARTIR de este usuario ya
 * autenticado), así que un Global Scope que deniega por defecto sin tenant activo
 * rompería la autenticación por completo. tenant_id se mantiene como columna (no
 * fillable, ver #[Fillable] abajo) y se asigna explícitamente en el servicio que crea
 * el usuario, nunca desde el payload del cliente.
 */
#[Fillable(['name', 'email', 'password', 'role', 'is_active'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable;

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
}
