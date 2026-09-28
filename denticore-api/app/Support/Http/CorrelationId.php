<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Id de correlación de cada solicitud (SDD §1.7, §1.12; RNF-125): se toma de la cabecera
 * `X-Correlation-Id` si es un UUID válido o se genera; se agrega al contexto de los
 * registros y se devuelve en la respuesta.
 */
class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    private const BINDING = 'correlation.id';

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && Str::isUuid($incoming) ? strtolower($incoming) : (string) Str::uuid();

        app()->instance(self::BINDING, $id);
        Log::withContext(['correlation_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    public static function current(): ?string
    {
        return app()->bound(self::BINDING) ? app(self::BINDING) : null;
    }
}
