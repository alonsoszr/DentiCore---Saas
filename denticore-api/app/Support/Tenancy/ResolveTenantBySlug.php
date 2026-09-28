<?php

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Middleware `tenant.slug[:login]` (SDD §1.6.2, §4.2; DD-29): resuelve la clínica por el
 * código de acceso (`{slug}` de la ruta o `tenant_slug` del cuerpo) entre las clínicas con
 * estado distinto de `eliminada` y la fija en TenantContext. Si falla: 401 "Credenciales
 * inválidas" en el login (RF-033) y 404 en el perfil público. En el login, la ausencia del
 * código significa un ingreso de super_admin.
 */
class ResolveTenantBySlug
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'public'): Response
    {
        $slug = $request->route('slug') ?? $request->input('tenant_slug');
        $isLogin = $mode === 'login';

        if (($slug === null || $slug === '') && $isLogin) {
            return $next($request);
        }

        $tenant = is_string($slug)
            ? Tenant::query()->where('slug', $slug)->where('status', '<>', 'eliminada')->first()
            : null;

        if ($tenant === null) {
            throw $isLogin ? new HttpException(401, 'Credenciales inválidas.') : new HttpException(404);
        }

        TenantContext::set($tenant);
        $request->attributes->set('tenant', $tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::clear();
    }
}
