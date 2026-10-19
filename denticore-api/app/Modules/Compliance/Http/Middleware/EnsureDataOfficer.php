<?php

namespace App\Modules\Compliance\Http\Middleware;

use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `data.officer` (SDD §3.8, §4.2; CUS-64 FE-1, CA-64.4): solo el administrador de la
 * clínica designado Oficial de Datos Personales (`users.is_data_officer`) atiende las solicitudes
 * ARCO; los demás reciben 403. Va después de `role:clinic_admin`.
 */
class EnsureDataOfficer
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_data_officer, 403);

        return $next($request);
    }
}
