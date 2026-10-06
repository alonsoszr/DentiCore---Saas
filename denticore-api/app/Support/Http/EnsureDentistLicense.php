<?php

namespace App\Support\Http;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `cop` (SDD §4.2; RN-75, RF-090): el odontólogo necesita su número de colegiatura
 * del COP para registrar datos clínicos. Se aplica después de `role:dentist`.
 */
class EnsureDentistLicense
{
    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws BusinessRuleException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || trim((string) $user->cop_number) === '') {
            throw new BusinessRuleException('RN-75', 'Registre su número de colegiatura del COP para registrar datos clínicos.');
        }

        return $next($request);
    }
}
