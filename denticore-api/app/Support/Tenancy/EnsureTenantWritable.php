<?php

namespace App\Support\Tenancy;

use App\Support\Http\BusinessRuleException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `tenant.writable` (SDD §4.2; RN-07, RF-006, RF-020):
 * - clínica `suspendida`: solo lectura (`GET`, `HEAD`, `OPTIONS`) y rutas marcadas
 *   `tenant.readonly_ok`;
 * - clínica `cancelada`: solo `clinic_admin` en rutas marcadas `tenant.exportable`;
 * - clínica `eliminada`: nada.
 * Corre después de `tenant`, con la clínica ya en el contexto.
 */
class EnsureTenantWritable
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = TenantContext::tenantOrFail();
        $marks = $request->route()?->gatherMiddleware() ?? [];

        $allowed = match ($tenant->status) {
            'activa' => true,
            'suspendida' => in_array($request->method(), self::READ_METHODS, true) || in_array('tenant.readonly_ok', $marks, true),
            'cancelada' => in_array('tenant.exportable', $marks, true) && $request->user()?->role === 'clinic_admin',
            default => false,
        };

        if (! $allowed) {
            throw new BusinessRuleException('RN-07', match ($tenant->status) {
                'suspendida' => 'La clínica está suspendida: solo se puede consultar información.',
                'cancelada' => 'La clínica está cancelada: solo su administrador puede exportar sus datos.',
                default => 'La clínica ya no está disponible.',
            }, status: 403);
        }

        return $next($request);
    }
}
