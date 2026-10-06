<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Segundo factor TOTP (SDD §1.7, §3.6; CUS-07, CUS-08; RF-037, RF-038, DD-15, DD-36): RFC 6238
 * con 6 dígitos, 30 s y ventana ±1; secreto cifrado con la clave maestra (cast `encrypted`).
 * Confirmar el 2FA genera 10 códigos de recuperación de un solo uso, guardados como hash, y
 * reemplaza el token por uno `full` (PEND-04). Un código incorrecto cuenta como intento de
 * inicio de sesión (RF-034).
 */
class TwoFactorService
{
    public const ISSUER = 'DentiCore';

    public const WINDOW = 1;

    public const RECOVERY_CODES = 10;

    public function __construct(
        private Google2FA $google2fa,
        private SessionTokenService $tokens,
        private AuthService $auth,
        private AuditLogger $audit,
    ) {}

    /**
     * CUS-08: secreto nuevo, sin confirmar, y la URL `otpauth://` para el QR.
     *
     * @return array{secret: string, otpauth_url: string}
     */
    public function setup(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return [
            'secret' => $secret,
            'otpauth_url' => $this->google2fa->getQRCodeUrl(self::ISSUER, $user->email, $secret),
        ];
    }

    /**
     * CUS-08: confirma el secreto con un código vigente.
     *
     * @return array{recovery_codes: list<string>, token: string, abilities: list<string>, expires_at: \DateTimeInterface|null, user: User}
     *
     * @throws ValidationException
     */
    public function confirm(User $user, string $code, ?string $ipAddress, ?string $userAgent): array
    {
        if ($user->two_factor_secret === null || ! $this->validCode($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => ['El código no es válido. Revisa la hora de tu teléfono o espera el siguiente código.']]);
        }

        return DB::transaction(function () use ($user, $ipAddress, $userAgent): array {
            $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_reset_required' => false])->save();
            $codes = $this->replaceRecoveryCodes($user);

            $this->inUserTenant($user, fn () => $this->audit->record(AuditEvent::Auth2faConfigured, $user, actor: $user));

            return ['recovery_codes' => $codes, ...$this->fullSession($user, $ipAddress, $userAgent)];
        });
    }

    /**
     * CUS-07: código TOTP o código de recuperación (un solo uso).
     *
     * @return array{token: string, abilities: list<string>, expires_at: \DateTimeInterface|null, user: User}
     *
     * @throws ValidationException
     */
    public function verify(User $user, ?string $code, ?string $recoveryCode, ?string $ipAddress, ?string $userAgent): array
    {
        if ($user->status === 'bloqueado_temporal' && $user->locked_until?->isFuture()) {
            $this->revokeCurrentToken($user);

            throw new HttpException(401, 'Credenciales inválidas.');
        }

        $passed = $recoveryCode !== null
            ? $this->consumeRecoveryCode($user, $recoveryCode)
            : $user->two_factor_secret !== null && $code !== null && $this->validCode($user->two_factor_secret, $code);

        if (! $passed) {
            // FE-5 de CUS-06: un segundo factor incorrecto cuenta como intento fallido.
            $this->auth->registerFailedAttempt($user->tenant, $user);
            $this->inUserTenant($user, fn () => $this->audit->record(AuditEvent::AuthLoginFailed, $user, actor: $user));

            $field = $recoveryCode !== null ? 'recovery_code' : 'code';
            throw ValidationException::withMessages([$field => ['Código incorrecto.']]);
        }

        return DB::transaction(fn (): array => $this->fullSession($user, $ipAddress, $userAgent));
    }

    private function validCode(string $secret, string $code): bool
    {
        return preg_match('/^\d{6}$/', $code) === 1 && $this->google2fa->verifyKey($secret, $code, self::WINDOW) !== false;
    }

    /**
     * Revoca el token actual (`2fa:setup` o `2fa:pending`) y emite uno `full`.
     *
     * @return array{token: string, abilities: list<string>, expires_at: \DateTimeInterface|null, user: User}
     */
    private function fullSession(User $user, ?string $ipAddress, ?string $userAgent): array
    {
        $this->revokeCurrentToken($user);
        $user->forceFill(['failed_login_count' => 0])->save();
        $token = $this->tokens->issue($user, ['full'], $ipAddress, $userAgent);

        return [
            'token' => $token->plainTextToken,
            'abilities' => ['full'],
            'expires_at' => $token->accessToken->expires_at,
            'user' => $this->auth->profile($user),
        ];
    }

    /**
     * DD-36: 10 códigos nuevos; los anteriores dejan de servir.
     *
     * @return list<string>
     */
    private function replaceRecoveryCodes(User $user): array
    {
        DB::table('two_factor_recovery_codes')->where('user_id', $user->id)->delete();

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $code = Str::upper(Str::random(5).'-'.Str::random(5));
            $codes[] = $code;
            DB::table('two_factor_recovery_codes')->insert([
                'user_id' => $user->id,
                'code_hash' => self::hashRecoveryCode($code),
                'created_at' => now(),
            ]);
        }

        return $codes;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        return DB::table('two_factor_recovery_codes')
            ->where('user_id', $user->id)
            ->where('code_hash', self::hashRecoveryCode($code))
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]) === 1;
    }

    private static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', Str::upper(trim($code)));
    }

    private function revokeCurrentToken(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    /**
     * @param  Closure(): mixed  $callback
     */
    private function inUserTenant(User $user, Closure $callback): void
    {
        $user->tenant === null ? $callback() : TenantContext::run($user->tenant, $callback);
    }
}
