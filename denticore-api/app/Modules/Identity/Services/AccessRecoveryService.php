<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Restablecer el acceso de un usuario (CUS-79; RF-052, RN-04, DD-36): el Administrador de Clínica
 * lo hace con los usuarios de su clínica y el Súper Administrador solo con los Administradores de
 * Clínica. La bitácora queda en la clínica del usuario.
 */
class AccessRecoveryService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * Levanta el bloqueo temporal por intentos fallidos (RF-034) antes de que venza.
     *
     * @throws BusinessRuleException
     */
    public function unlock(User $user): User
    {
        if ($user->status !== 'bloqueado_temporal') {
            throw new BusinessRuleException('RF-052', 'La cuenta no está bloqueada.', status: 409);
        }

        DB::transaction(function () use ($user): void {
            $user->forceFill(['status' => 'activo', 'failed_login_count' => 0, 'locked_until' => null])->save();
            $this->inUserTenant($user, fn () => $this->audit->record(AuditEvent::UserUnlocked, $user));
        });

        return $user;
    }

    /**
     * Borra el segundo factor y sus códigos, cierra las sesiones del usuario y le exige configurarlo
     * otra vez en el siguiente inicio de sesión (`2fa:setup`, SDD §3.6).
     *
     * @param  'administrador'|'plataforma'  $origin
     */
    public function resetTwoFactor(User $user, string $origin): User
    {
        DB::transaction(function () use ($user, $origin): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_reset_required' => true,
            ])->save();
            DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            $this->inUserTenant($user, fn () => $this->audit->record(AuditEvent::Auth2faReset, $user, meta: ['origen' => $origin]));
        });

        return $user;
    }

    /**
     * Cuenta de clínica para las rutas de plataforma (404 si no existe). RN-04: el Súper
     * Administrador solo actúa sobre cuentas de Administrador de Clínica (403 con otro rol).
     *
     * @throws BusinessRuleException
     * @throws ModelNotFoundException<User>
     */
    public function clinicAdmin(string $uuid): User
    {
        $user = User::query()->whereNotNull('tenant_id')->where('uuid', $uuid)->firstOrFail();

        if ($user->role !== 'clinic_admin') {
            throw new BusinessRuleException('RN-04', 'El Súper Administrador solo restablece el acceso de los Administradores de Clínica.', status: 403);
        }

        return $user;
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function inUserTenant(User $user, Closure $callback): void
    {
        $user->tenant === null ? $callback() : TenantContext::run($user->tenant, $callback);
    }
}
