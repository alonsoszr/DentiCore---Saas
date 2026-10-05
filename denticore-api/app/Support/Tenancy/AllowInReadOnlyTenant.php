<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca de ruta `tenant.readonly_ok` (SDD §4.2; SRS §9.3 CUS-05, RN-70): rutas `POST` que solo generan
 * documentos de lectura, permitidas con la clínica `suspendida`.
 * No hace nada por sí sola: la lee EnsureTenantWritable.
 */
class AllowInReadOnlyTenant
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
