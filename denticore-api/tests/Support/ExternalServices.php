<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * Servicios externos simulados (SDD §6.2): motor ML, IA generativa y otros HTTP.
 *
 * Nota: SDD §6.2 menciona `Http::response()->delay` para simular latencia, pero Laravel 13
 * no ofrece ese método. Un servicio que no responde a tiempo se simula con
 * `Http::failedConnection()` (el mismo resultado que un timeout, sin esperar reloj real,
 * que T-161 prohíbe).
 */
final class ExternalServices
{
    /**
     * @param  array<string, mixed>  $body
     */
    public static function respondsWith(string $urlPattern, array $body, int $status = 200): void
    {
        Http::fake([$urlPattern => Http::response($body, $status)]);
    }

    public static function timesOut(string $urlPattern): void
    {
        Http::fake([$urlPattern => Http::failedConnection('Operation timed out')]);
    }
}
