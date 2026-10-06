<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Scheduling\Enums\NotificationEvent;
use App\Modules\Scheduling\Services\NotificationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Inicio y cierre de sesión (CUS-06, CUS-10; SRS §11.2, SDD §1.7, §1.8). Toda falla responde el
 * mismo 401 "Credenciales inválidas" (RF-033, FE-1, FE-2, FE-4). Al 5.º fallo consecutivo el
 * usuario queda `bloqueado_temporal` 15 minutos y recibe un correo (RF-034).
 */
class AuthService
{
    public const MAX_FAILED_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    /** Roles que deben tener segundo factor (SDD §1.7, CA-06.4). */
    public const TWO_FACTOR_ROLES = ['super_admin', 'clinic_admin'];

    public function __construct(
        private AuditLogger $audit,
        private SessionTokenService $tokens,
        private NotificationService $notifications,
    ) {}

    /**
     * @param  Tenant|null  $tenant  Clínica del código de acceso (null: Súper Administrador, FA-1).
     * @return array{token: string, abilities: list<string>, expires_at: \DateTimeInterface, user: User|null}
     *
     * @throws HttpException
     */
    public function login(?Tenant $tenant, string $email, string $password, ?string $ipAddress, ?string $userAgent): array
    {
        $user = User::query()
            ->when($tenant, fn ($query) => $query->where('tenant_id', $tenant?->id), fn ($query) => $query->whereNull('tenant_id'))
            ->where('email', mb_strtolower(trim($email)))
            ->first();

        // FE-4: en una clínica cancelada solo continúa su administrador (RN-07, FA-3).
        if ($user !== null && $tenant?->status === 'cancelada' && $user->role !== 'clinic_admin') {
            $this->fail($tenant, $user, countAttempt: false);
        }

        if ($user === null) {
            $this->fail($tenant, null, countAttempt: false);
        }

        if ($this->isLocked($user)) {
            $this->fail($tenant, $user, countAttempt: false);
        }

        if (! $this->canLogIn($user) || $user->password === null || ! Hash::check($password, $user->password)) {
            $this->fail($tenant, $user, countAttempt: true);
        }

        return DB::transaction(function () use ($tenant, $user, $ipAddress, $userAgent): array {
            $user->forceFill(['failed_login_count' => 0, 'status' => 'activo', 'locked_until' => null, 'last_login_at' => now()])->save();

            $abilities = $this->abilitiesFor($user);
            $token = $this->tokens->issue($user, $abilities, $ipAddress, $userAgent);

            $this->inTenant($tenant, fn () => $this->audit->record(AuditEvent::AuthLoginOk, $user, actor: $user));

            return [
                'token' => $token->plainTextToken,
                'abilities' => $abilities,
                'expires_at' => $token->accessToken->expires_at,
                'user' => $abilities === ['full'] ? $this->profile($user) : null,
            ];
        });
    }

    /**
     * SDD §1.7: 2FA confirmado → `2fa:pending`; SA o CA sin 2FA → `2fa:setup`; resto → `full`.
     *
     * @return list<string>
     */
    public function abilitiesFor(User $user): array
    {
        return match (true) {
            $user->two_factor_confirmed_at !== null => ['2fa:pending'],
            in_array($user->role, self::TWO_FACTOR_ROLES, true) => ['2fa:setup'],
            default => ['full'],
        };
    }

    /**
     * Cuenta un intento fallido (también el segundo factor incorrecto, FE-5) y bloquea al 5.º.
     */
    public function registerFailedAttempt(?Tenant $tenant, User $user): void
    {
        DB::transaction(function () use ($tenant, $user): void {
            $attempts = $user->failed_login_count + 1;
            $locks = $attempts >= self::MAX_FAILED_ATTEMPTS;

            $user->forceFill([
                'failed_login_count' => $locks ? 0 : $attempts,
                'status' => $locks ? 'bloqueado_temporal' : $user->status,
                'locked_until' => $locks ? now()->addMinutes(self::LOCK_MINUTES) : $user->locked_until,
            ])->save();

            if ($locks) {
                $this->inTenant($tenant, function () use ($user): void {
                    $this->audit->record(AuditEvent::AuthLocked, $user, actor: $user);
                    $this->notifications->sendEmail(NotificationEvent::CuentaBloqueada, $user);
                });
            }
        });
    }

    public function logout(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->currentAccessToken()->delete();

            $this->inTenant($user->tenant, fn () => $this->audit->record(AuditEvent::AuthLogout, $user, actor: $user));
        });
    }

    /**
     * Usuario con su clínica y, para cuentas de portal, su ficha vinculada. La ficha se lee
     * en el contexto de la clínica del usuario (RLS, SDD §2.13).
     */
    public function profile(User $user): User
    {
        $user->loadMissing('tenant.plan');

        if ($user->tenant === null) {
            return $user;
        }

        return TenantContext::run($user->tenant, fn (): User => $user->loadMissing('patient'));
    }

    /**
     * @return never
     */
    private function fail(?Tenant $tenant, ?User $user, bool $countAttempt): void
    {
        if ($user !== null && $countAttempt) {
            $this->registerFailedAttempt($tenant, $user);
        }

        // No se guarda el correo intentado (RN-67).
        $this->inTenant($tenant, fn () => $this->audit->record(AuditEvent::AuthLoginFailed, $user, actor: $user));

        throw new HttpException(401, 'Credenciales inválidas.');
    }

    private function isLocked(User $user): bool
    {
        return $user->status === 'bloqueado_temporal' && $user->locked_until !== null && $user->locked_until->isFuture();
    }

    /**
     * Activo, o bloqueado con el bloqueo ya vencido (SRS §11.2, paso 6).
     */
    private function canLogIn(User $user): bool
    {
        return $user->status === 'activo' || ($user->status === 'bloqueado_temporal' && ! $this->isLocked($user));
    }

    /**
     * Los eventos de sesión quedan en la cadena de la clínica (o en la de plataforma para
     * super_admin).
     */
    /**
     * @param  Closure(): mixed  $callback
     */
    private function inTenant(?Tenant $tenant, Closure $callback): void
    {
        $tenant === null ? $callback() : TenantContext::run($tenant, $callback);
    }
}
