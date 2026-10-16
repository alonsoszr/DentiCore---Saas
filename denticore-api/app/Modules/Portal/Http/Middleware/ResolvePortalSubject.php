<?php

namespace App\Modules\Portal\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Services\PortalSubjectResolver;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `portal.subject` (SDD §3.3, §4.2; RN-03, CA-37.5): el `{patient}` de la ruta, o el
 * paciente dueño de `{budget}` o `{appointment}`, debe ser un sujeto de la cuenta; si no, 404.
 * Deja el paciente en el atributo `portal.subject` de la solicitud.
 */
class ResolvePortalSubject
{
    public const ATTRIBUTE = 'portal.subject';

    public function __construct(private PortalSubjectResolver $resolver) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $patient = $this->patient($request);

        abort_unless(
            $patient !== null && $this->resolver->for($user)->contains('id', $patient->id),
            404,
        );

        $request->attributes->set(self::ATTRIBUTE, $patient);

        return $next($request);
    }

    private function patient(Request $request): ?Patient
    {
        $patient = $request->route('patient');

        if ($patient instanceof Patient) {
            return $patient;
        }

        foreach ((array) $request->route()?->parameters() as $parameter) {
            if ($parameter instanceof Model && method_exists($parameter, 'patient')) {
                $owner = $parameter->getRelationValue('patient');

                return $owner instanceof Patient ? $owner : null;
            }
        }

        return null;
    }
}
