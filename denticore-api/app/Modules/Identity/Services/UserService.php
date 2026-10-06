<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Gestión de usuarios de una clínica (CUS-11; RF-042 a RF-047).
 *
 * User no lleva Global Scope (SDD §1.6.1, excepción `users`), así que el aislamiento por clínica
 * es explícito en cada consulta (SDD §3.7, `UserRepository`): un UUID de otra clínica → 404.
 * Las altas son por invitación (DD-22); el máximo de odontólogos del plan (RN-08) y la
 * protección del último administrador y del último oficial de datos (RF-045) se verifican con
 * la fila de la clínica bloqueada.
 */
class UserService
{
    public const EDITABLE = ['name', 'email', 'role', 'cop_number', 'specialty', 'rne_number'];

    public function __construct(private AuditLogger $audit, private InvitationService $invitations) {}

    /**
     * @param  array{role?: string|null, status?: string|null, per_page?: int|null}  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(Tenant $tenant, array $filters): LengthAwarePaginator
    {
        return User::query()
            ->where('tenant_id', $tenant->id)
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->where('role', $role))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->with('patient')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);
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
     * El tenant_id sale siempre del contexto de sesión, nunca del payload.
     *
     * @param  array{name: string, email: string, role: string, cop_number?: string|null, specialty?: string|null, rne_number?: string|null, is_data_officer?: bool}  $attributes
     */
    public function create(Tenant $tenant, array $attributes): User
    {
        return DB::transaction(function () use ($tenant, $attributes): User {
            $this->lockTenant($tenant);

            if ($attributes['role'] === 'dentist') {
                $this->ensureDentistSlot($tenant);
            }

            $user = new User(Arr::only($attributes, self::EDITABLE));
            $user->tenant_id = $tenant->id;
            $user->is_data_officer = (bool) ($attributes['is_data_officer'] ?? false);
            $user->save();

            $this->audit->record(AuditEvent::UserCreated, $user);
            $this->invitations->send($user);

            return $user;
        });
    }

    /**
     * @param  array{name?: string, email?: string, role?: string, cop_number?: string|null, specialty?: string|null, rne_number?: string|null, is_data_officer?: bool}  $attributes
     */
    public function update(User $user, array $attributes): User
    {
        return DB::transaction(function () use ($user, $attributes): User {
            $tenant = $this->lockTenant($user->tenant);
            $user->fill(Arr::only($attributes, self::EDITABLE));

            if (array_key_exists('is_data_officer', $attributes)) {
                $user->is_data_officer = (bool) $attributes['is_data_officer'];
            }

            // Un usuario que deja de ser administrador deja de ser oficial de datos (CHECK de §2.4).
            if ($user->role !== 'clinic_admin') {
                $user->is_data_officer = false;
            }

            if ($user->isDirty('role') && $user->role === 'dentist' && $user->status === 'activo') {
                $this->ensureDentistSlot($tenant);
            }

            $this->ensureNotLastGuardian($user, losesAdmin: $user->isDirty('role') && $user->getOriginal('role') === 'clinic_admin',
                losesOfficer: $user->isDirty('is_data_officer') && $user->getOriginal('is_data_officer') === true);

            $user->save();
            $this->auditChanges($user);

            return $user;
        });
    }

    /**
     * RF-044: desactivar revoca todas las sesiones en el momento.
     */
    public function deactivate(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $this->lockTenant($user->tenant);
            $this->ensureNotLastGuardian($user, losesAdmin: $user->role === 'clinic_admin', losesOfficer: $user->is_data_officer);

            $user->forceFill(['status' => 'inactivo', 'deactivated_at' => now()])->save();
            $user->tokens()->delete();

            $this->audit->record(AuditEvent::UserDeactivated, $user, ['status']);

            return $user;
        });
    }

    /**
     * RF-046: un odontólogo solo se reactiva si el plan tiene cupo.
     */
    public function reactivate(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $tenant = $this->lockTenant($user->tenant);

            if ($user->role === 'dentist' && $user->status !== 'activo') {
                $this->ensureDentistSlot($tenant);
            }

            // Una cuenta que nunca se activó vuelve a quedar pendiente de su invitación.
            $user->forceFill(['status' => $user->password === null ? 'pendiente_activacion' : 'activo', 'deactivated_at' => null])->save();

            $this->audit->record(AuditEvent::UserReactivated, $user, ['status']);

            return $user;
        });
    }

    public function resendInvitation(User $user): void
    {
        if ($user->status !== 'pendiente_activacion') {
            throw new BusinessRuleException('RF-042', 'El usuario ya activó su cuenta.', status: 409);
        }

        $this->invitations->send($user);
    }

    private function lockTenant(?Tenant $tenant): Tenant
    {
        return Tenant::query()->with('plan')->whereKey($tenant?->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * RN-08, RF-046: el plan permite un odontólogo activo más.
     */
    private function ensureDentistSlot(Tenant $tenant): void
    {
        $max = $tenant->plan?->max_dentists;

        if ($max === null) {
            return;
        }

        $active = User::query()->where('tenant_id', $tenant->id)->where('role', 'dentist')
            ->whereIn('status', ['activo', 'pendiente_activacion', 'bloqueado_temporal'])->count();

        if ($active >= $max) {
            throw new BusinessRuleException('RN-08', "El plan de la clínica permite {$max} odontólogos activos.", [
                'role' => ["Se alcanzó el máximo de {$max} odontólogos del plan."],
            ]);
        }
    }

    /**
     * RF-045: la clínica conserva al menos un administrador activo y un oficial de datos activo.
     */
    private function ensureNotLastGuardian(User $user, bool $losesAdmin, bool $losesOfficer): void
    {
        $others = fn () => User::query()
            ->where('tenant_id', $user->tenant_id)
            ->whereKeyNot($user->id)
            ->where('role', 'clinic_admin')
            ->where('status', 'activo');

        if ($losesAdmin && ! $others()->exists()) {
            throw new BusinessRuleException('RF-045', 'La clínica debe conservar al menos un Administrador de Clínica activo.');
        }

        if ($losesOfficer && ! $others()->where('is_data_officer', true)->exists()) {
            throw new BusinessRuleException('RF-045', 'La clínica debe conservar al menos un Oficial de Datos Personales activo.');
        }
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

        if (in_array('is_data_officer', $changed, true)) {
            $this->audit->record(AuditEvent::UserDataOfficerChanged, $user, ['is_data_officer']);
        }

        $otherFields = array_values(array_diff($changed, ['role', 'is_data_officer', 'status']));

        if ($otherFields !== []) {
            $this->audit->record(AuditEvent::UserUpdated, $user, $otherFields);
        }
    }
}
