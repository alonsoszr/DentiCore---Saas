<?php

namespace App\Support\Tenancy;

use App\Modules\Platform\Models\Tenant;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware `tenant.token:<propósito>` (SDD §1.6.2, §4.2; DI-15, RNF-112): resuelve la
 * clínica desde el token de un solo uso (`{token}` de la ruta o `token` del cuerpo) y la fija en TenantContext
 * antes de cargar el recurso. Un token inexistente, vencido o usado responde el mismo 404.
 * El token vigente queda en `$request->attributes->get('one_time_token')`.
 */
class ResolveTenantByToken
{
    public function __construct(private OneTimeTokenService $tokens) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        // En la ruta (`{token}`) o, en `POST /auth/password/reset`, en el cuerpo.
        $plain = $request->route('token') ?? $request->input('token');
        $record = is_string($plain) ? $this->tokens->find($plain, TokenPurpose::from($purpose)) : null;

        abort_if($record === null, 404);

        if ($record->tenant_id !== null) {
            TenantContext::set(Tenant::query()->findOrFail($record->tenant_id));
        }

        $request->attributes->set('one_time_token', $record);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::clear();
    }
}
