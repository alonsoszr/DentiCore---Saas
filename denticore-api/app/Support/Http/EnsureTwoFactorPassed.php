<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `2fa` (SDD §4.2, §3.6; RF-037, CA-06.4): el token debe tener la habilidad `full`.
 * Un token `2fa:pending` o `2fa:setup` recibe 403 `two_factor_required`.
 */
class EnsureTwoFactorPassed
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->tokenCan('full') !== true) {
            throw new BusinessRuleException('two_factor_required', 'Complete la verificación en dos pasos para continuar.', status: 403);
        }

        return $next($request);
    }
}
