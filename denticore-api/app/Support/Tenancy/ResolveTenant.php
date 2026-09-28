<?php

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `tenant` (SDD §1.6.2, §4.2): toma la clínica del usuario autenticado, la fija
 * en TenantContext (y en `app.tenant_id` para la RLS) y la limpia al terminar. Corre antes
 * de SubstituteBindings para que el model binding pase por el Global Scope. Un usuario sin
 * clínica en una ruta de clínica recibe 403.
 */
class ResolveTenant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->user()?->tenant_id;
        $tenant = $tenantId ? Tenant::query()->find($tenantId) : null;

        if (! $tenant) {
            abort(403);
        }

        TenantContext::set($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::clear();
    }
}
