<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorización gruesa a nivel de ruta por rol (technical_specs.md §4.2). Uso en rutas:
 * ->middleware('EnsureRole:super_admin') o ->middleware('EnsureRole:dentist,receptionist').
 */
class EnsureRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
