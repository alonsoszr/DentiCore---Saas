<?php

namespace App\Support\Http;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Middleware `token.fresh` (SDD §4.2; RF-036, RF-044): verificación complementaria del
 * vencimiento absoluto (12 h, `expires_at`) y del estado del usuario (`activo`). La inactividad
 * se evalúa antes, en `Sanctum::authenticateAccessTokensUsing`.
 */
class EnforceTokenFreshness
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $expiresAt = $token instanceof PersonalAccessToken ? $token->expires_at : null;
        $expired = $expiresAt instanceof CarbonInterface && $expiresAt->isPast();
        $inactiveUser = $user !== null && $user->getAttribute('status') !== 'activo';

        if ($expired || $inactiveUser) {
            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }

            throw new HttpException(401, 'La sesión ya no es válida. Inicie sesión nuevamente.');
        }

        return $next($request);
    }
}
