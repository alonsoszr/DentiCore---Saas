<?php

namespace App\Support\Tenancy;

use App\Support\Http\BusinessRuleException;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `plan.feature:<ai|risk|analytics>` (SDD §4.2; RN-08, RF-023, DD-16): el plan de
 * la clínica debe incluir la función; si no, 403 `plan_feature_unavailable`. Al bajar de plan
 * las rutas de lectura del historial no llevan esta marca y siguen disponibles.
 */
class EnsurePlanFeature
{
    private const COLUMNS = ['ai' => 'includes_ai', 'risk' => 'includes_risk', 'analytics' => 'includes_analytics'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $column = self::COLUMNS[$feature] ?? throw new InvalidArgumentException("Función de plan desconocida: {$feature}.");

        if (TenantContext::tenantOrFail()->plan?->getAttribute($column) !== true) {
            throw new BusinessRuleException(
                'plan_feature_unavailable',
                'El plan de la clínica no incluye esta función.',
                status: 403,
            );
        }

        return $next($request);
    }
}
