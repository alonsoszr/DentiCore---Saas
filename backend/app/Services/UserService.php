<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Gestión de usuarios de una clínica (technical_specs.md §5.2).
 *
 * User no lleva Global Scope (ver nota en app/Models/User.php), así que el
 * aislamiento por tenant es explícito en cada método de este servicio.
 */
class UserService
{
    /**
     * @return Collection<int, User>
     */
    public function listForTenant(Tenant $tenant): Collection
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->with('patient')
            ->orderBy('name')
            ->get();
    }

    /**
     * @throws ModelNotFoundException
     */
    public function findForTenant(Tenant $tenant, string $uuid): User
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * El tenant_id sale siempre del contexto de sesión, nunca de $attributes.
     *
     * @param  array{name: string, email: string, password: string, role: string, is_active?: bool}  $attributes
     */
    public function create(Tenant $tenant, array $attributes): User
    {
        $user = new User($attributes);
        $user->tenant_id = $tenant->id;
        $user->save();

        return $user;
    }

    /**
     * Desactivar un usuario revoca sus tokens: el login ya rechaza is_active=false, pero
     * sin esto los tokens emitidos antes de la desactivación seguirían siendo válidos.
     *
     * @param  array{name?: string, email?: string, password?: string, role?: string, is_active?: bool}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return $user;
    }
}
