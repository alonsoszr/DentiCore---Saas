<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Tokens de sesión (SDD §1.7, §2.4; RF-036, RF-050, RNF-115): Sanctum con vencimiento absoluto
 * de 12 h, habilidad `2fa:pending`, `2fa:setup` o `full`, y la clínica, IP y agente de la
 * sesión. Un usuario conserva como máximo 5 tokens `full`: al emitir el sexto se borra el más
 * antiguo.
 */
class SessionTokenService
{
    public const LIFETIME_HOURS = 12;

    public const MAX_FULL_TOKENS = 5;

    /**
     * @param  list<string>  $abilities
     */
    public function issue(User $user, array $abilities, ?string $ipAddress, ?string $userAgent): NewAccessToken
    {
        $token = $user->createToken('api', $abilities, now()->addHours(self::LIFETIME_HOURS));

        $token->accessToken->forceFill([
            'tenant_id' => $user->tenant_id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 297),
        ])->save();

        if ($abilities === ['full']) {
            $this->pruneFullTokens($user);
        }

        return $token;
    }

    private function pruneFullTokens(User $user): void
    {
        $user->tokens()
            ->where('abilities', json_encode(['full']))
            ->orderByDesc('id')
            ->skip(self::MAX_FULL_TOKENS)
            ->take(PHP_INT_MAX)
            ->get()
            ->each(fn (PersonalAccessToken $token) => $token->delete());
    }
}
