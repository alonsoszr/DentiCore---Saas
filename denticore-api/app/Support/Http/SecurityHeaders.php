<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad de SDD §1.7 (DD-44, RNF-090, RNF-095) en las respuestas de la API.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->add([
            'Content-Security-Policy' => $this->contentSecurityPolicy(),
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        ]);

        return $response;
    }

    /**
     * CSP de SDD §1.7 con los orígenes del almacenamiento (<bucket>) y de la API (<api>).
     */
    private function contentSecurityPolicy(): string
    {
        $api = rtrim((string) config('app.url'), '/');
        $bucket = rtrim((string) (config('filesystems.disks.s3.url') ?: config('filesystems.disks.s3.endpoint')), '/');

        return "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob: {$bucket}; "
            ."connect-src 'self' {$api}; frame-ancestors 'none'; base-uri 'none'; form-action 'self'";
    }
}
