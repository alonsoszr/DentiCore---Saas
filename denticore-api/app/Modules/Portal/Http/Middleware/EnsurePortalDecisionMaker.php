<?php

namespace App\Modules\Portal\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Services\PortalSubjectResolver;
use App\Support\Http\BusinessRuleException;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `portal.adult` (SDD §3.3, §4.2; RN-12, SRS §11.10 FE-5): las decisiones del portal
 * (presupuesto, consentimiento) las toma el titular adulto o el representante vigente. Un titular
 * menor recibe 403. Va después de `portal.subject`.
 */
class EnsurePortalDecisionMaker
{
    public function __construct(private PortalSubjectResolver $resolver) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws BusinessRuleException
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $patient = $request->attributes->get(ResolvePortalSubject::ATTRIBUTE);

        if (! $patient instanceof Patient) {
            throw new LogicException('portal.adult necesita el sujeto resuelto por portal.subject.');
        }

        if ($this->resolver->isHolder($user, $patient) && $patient->isMinorOn()) {
            throw new BusinessRuleException('RN-12', 'La decisión corresponde al representante legal.', status: 403);
        }

        return $next($request);
    }
}
