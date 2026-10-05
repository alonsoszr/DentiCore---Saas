<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca de ruta `tenant.exportable` (SDD §4.2; RF-020): ruta permitida al `clinic_admin` de una
 * clínica cancelada durante 90 días.
 * No hace nada por sí sola: la lee EnsureTenantWritable.
 */
class AllowCancelledExport
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
