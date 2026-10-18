<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Perfil propio (CUS-78; RF-049, RF-040, RN-67). Las rutas AUTH no fijan el contexto de clínica:
 * la bitácora se escribe en la clínica del usuario (o en la de plataforma para el Súper
 * Administrador), como en TwoFactorService.
 */
class ProfileService
{
    public function __construct(
        private PasswordService $passwords,
        private AuditLogger $audit,
    ) {}

    public function updateName(User $user, string $name): User
    {
        if ($user->name === $name) {
            return $user;
        }

        DB::transaction(function () use ($user, $name): void {
            $user->forceFill(['name' => $name])->save();
            $this->inUserTenant($user, fn () => $this->audit->record(AuditEvent::UserUpdated, $user, ['name'], actor: $user));
        });

        return $user;
    }

    /**
     * La sesión desde la que se cambia sigue abierta; las demás se cierran (DD-15).
     */
    public function changePassword(User $user, string $password, ?int $currentTokenId): void
    {
        DB::transaction(function () use ($user, $password, $currentTokenId): void {
            $this->passwords->set($user, $password, keepTokenId: $currentTokenId);
            $this->inUserTenant($user, fn () => $this->audit->record(AuditEvent::AuthPasswordChanged, $user, actor: $user));
        });
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function inUserTenant(User $user, Closure $callback): void
    {
        $user->tenant === null ? $callback() : TenantContext::run($user->tenant, $callback);
    }
}
