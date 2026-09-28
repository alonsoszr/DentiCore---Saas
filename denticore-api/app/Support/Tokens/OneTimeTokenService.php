<?php

namespace App\Support\Tokens;

use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tokens de un solo uso (SDD §2.4, DI-15; DD-15, DD-22, RNF-111, RNF-112): invitaciones,
 * restablecimiento de contraseña, confirmación de citas, encuestas y enlaces compartidos.
 *
 * - El token tiene 256 bits aleatorios (≥ 128) y se entrega una sola vez; la BD guarda su
 *   SHA-256.
 * - Un token vencido, usado, invalidado o inexistente es indistinguible: `find` devuelve null
 *   y la ruta responde el mismo 404 (RNF-112).
 * - Cada intento fallido suma `failed_attempts`; al 5.º el token queda invalidado (RNF-111).
 */
class OneTimeTokenService
{
    /**
     * @return array{token: string, record: OneTimeToken}
     */
    public function issue(Model $tokenable, TokenPurpose $purpose, DateTimeInterface $expiresAt): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $record = OneTimeToken::query()->create([
            'tenant_id' => TenantContext::id() ?? $tokenable->getAttribute('tenant_id'),
            'purpose' => $purpose,
            'token_hash' => self::hash($token),
            'tokenable_type' => $tokenable->getMorphClass(),
            'tokenable_id' => $tokenable->getKey(),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'record' => $record];
    }

    /**
     * Token vigente del propósito indicado, o null si no existe o ya no se puede usar.
     */
    public function find(string $token, TokenPurpose $purpose): ?OneTimeToken
    {
        $record = OneTimeToken::query()
            ->where('token_hash', self::hash($token))
            ->where('purpose', $purpose)
            ->first();

        return $record?->isUsable() ? $record : null;
    }

    /**
     * Marca el token como usado. El enlace de presupuesto compartido sigue vigente.
     */
    public function consume(OneTimeToken $record): void
    {
        if (! $record->purpose->isReusable()) {
            $record->forceFill(['used_at' => now()])->save();
        }
    }

    /**
     * Registra un intento fallido (código u otro dato de verificación incorrecto). Con el
     * 5.º fallo el token queda invalidado.
     */
    public function registerFailure(OneTimeToken $record): void
    {
        DB::transaction(function () use ($record): void {
            $locked = OneTimeToken::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $attempts = $locked->failed_attempts + 1;

            $locked->forceFill([
                'failed_attempts' => $attempts,
                'invalidated_at' => $attempts >= OneTimeToken::MAX_FAILED_ATTEMPTS ? now() : $locked->invalidated_at,
            ])->save();

            $record->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Invalida los tokens vigentes de un recurso y propósito (p. ej. al reenviar una invitación).
     */
    public function invalidateFor(Model $tokenable, TokenPurpose $purpose): int
    {
        return OneTimeToken::query()
            ->where('tokenable_type', $tokenable->getMorphClass())
            ->where('tokenable_id', $tokenable->getKey())
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => now()]);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
