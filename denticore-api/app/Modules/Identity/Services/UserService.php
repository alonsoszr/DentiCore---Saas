<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Gestión de usuarios de una clínica (CUS-11).
 *
 * User no lleva Global Scope (SDD §1.6.1, excepción `users`), así que el aislamiento
 * por clínica es explícito en cada método de este servicio (SDD §3.7).
 */
class UserService
{
    public function __construct(private AuditLogger $audit) {}

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
        return DB::transaction(function () use ($tenant, $attributes): User {
            $user = new User($attributes);
            $user->tenant_id = $tenant->id;
            $user->save();

            $this->audit->record(AuditEvent::UserCreated, $user);

            return $user;
        });
    }

    /**
     * Desactivar un usuario revoca sus tokens: el login ya rechaza is_active=false, pero
     * sin esto los tokens emitidos antes de la desactivación seguirían siendo válidos.
     *
     * @param  array{name?: string, email?: string, password?: string, role?: string, is_active?: bool}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $user->fill($attributes)->save();

            if (! $user->is_active) {
                $user->tokens()->delete();
            }

            $this->auditChanges($user);

            return $user;
        });
    }

    /**
     * Un evento por tipo de cambio (SDD §5.14), solo con nombres de campos (RF-062).
     */
    private function auditChanges(User $user): void
    {
        $changed = array_values(array_diff(array_keys($user->getChanges()), ['updated_at']));

        if (in_array('role', $changed, true)) {
            $this->audit->record(AuditEvent::UserRoleChanged, $user, ['role']);
        }

        if (in_array('is_active', $changed, true)) {
            $this->audit->record($user->is_active ? AuditEvent::UserReactivated : AuditEvent::UserDeactivated, $user, ['is_active']);
        }

        // `status` cambia junto con `is_active` mientras conviven (TASK-026): es el mismo evento.
        $otherFields = array_values(array_diff($changed, ['role', 'is_active', 'status']));

        if ($otherFields !== []) {
            $this->audit->record(AuditEvent::UserUpdated, $user, $otherFields);
        }
    }
}
