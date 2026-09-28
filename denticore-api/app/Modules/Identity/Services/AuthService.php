<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Inicio y cierre de sesión (CUS-06, CUS-10). Comportamiento heredado de las fases 0–3:
 * login con `tenant_slug` (sin él, login de super_admin) y token Sanctum sin
 * habilidades ni vencimiento; TASK-027 agrega bloqueo, límites, 2FA y vencimientos.
 */
class AuthService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * El email es único por clínica (UNIQUE(tenant_id, email)), por eso el login se
     * resuelve dentro de la clínica del `tenant_slug` (SDD §3.7).
     *
     * @return array{token: string, user: User}
     *
     * @throws ValidationException
     */
    public function login(?string $tenantSlug, string $email, string $password): array
    {
        $usersQuery = User::query();
        $tenant = null;

        if ($tenantSlug) {
            $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();
            $usersQuery->where('tenant_id', $tenant->id);
        } else {
            $usersQuery->whereNull('tenant_id');
        }

        $user = $usersQuery->where('email', $email)->first();

        if (! $user || ! $user->is_active || ! Hash::check($password, $user->password)) {
            $this->auditInTenant($tenant, AuditEvent::AuthLoginFailed, $user);

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        $this->auditInTenant($tenant, AuditEvent::AuthLoginOk, $user);

        return [
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $this->profile($user),
        ];
    }

    /**
     * Los eventos de sesión quedan en la cadena de la clínica del login (o en la de
     * plataforma para super_admin). No se guarda el correo intentado (RN-67).
     */
    private function auditInTenant(?Tenant $tenant, AuditEvent $event, ?User $user): void
    {
        $record = fn () => $this->audit->record($event, $user, actor: $user);

        $tenant ? TenantContext::run($tenant, $record) : $record();
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * Usuario con su clínica y, para cuentas de portal, su ficha vinculada. La ficha se lee
     * en el contexto de la clínica del usuario (RLS, SDD §2.13).
     */
    public function profile(User $user): User
    {
        $user->loadMissing('tenant');

        if ($user->tenant === null) {
            return $user;
        }

        return TenantContext::run($user->tenant, fn (): User => $user->loadMissing('patient'));
    }
}
