<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * technical_specs.md §2.3 punto 3: resuelve el tenant activo desde el usuario autenticado
 * y lo fija en un singleton de contexto para el resto del request.
 */
class ResolveTenant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;

        if ($tenantId) {
            $tenant = Tenant::query()->find($tenantId);

            if ($tenant) {
                app()->instance('currentTenant', $tenant);
            }
        }

        return $next($request);
    }
}
