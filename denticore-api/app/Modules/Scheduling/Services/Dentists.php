<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Odontólogos de la clínica actual por su uuid público (RF-007). `users` no tiene Global Scope
 * (DD-03), así que el filtro por clínica es explícito; otro usuario o clínica → 404.
 */
class Dentists
{
    /**
     * @throws ModelNotFoundException<User>
     */
    public function find(string $uuid): User
    {
        return User::query()
            ->where('tenant_id', TenantContext::id())
            ->where('role', 'dentist')
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    /**
     * Odontólogos activos por nombre; solo el indicado si se pasa su uuid.
     *
     * @return Collection<int, User>
     */
    public function active(?string $uuid = null): Collection
    {
        return User::query()
            ->where('tenant_id', TenantContext::id())
            ->where('role', 'dentist')
            ->where('status', 'activo')
            ->when($uuid !== null, fn ($query) => $query->where('uuid', $uuid))
            ->orderBy('name')
            ->get();
    }

    public function findOrNull(?string $uuid): ?User
    {
        if ($uuid === null) {
            return null;
        }

        return User::query()
            ->where('tenant_id', TenantContext::id())
            ->where('role', 'dentist')
            ->where('uuid', $uuid)
            ->first();
    }
}
