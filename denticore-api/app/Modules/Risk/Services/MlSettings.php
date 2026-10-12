<?php

namespace App\Modules\Risk\Services;

use Illuminate\Support\Facades\DB;

/**
 * Parámetros del motor ML en `platform_settings` (SDD §2.3; IE-02, DI-12, RN-64): timeout de la
 * llamada y umbrales del Circuit Breaker. Si una clave falta se usan los valores del SDD
 * (3 s, 5 fallos, 60 s).
 */
final readonly class MlSettings
{
    public const CONNECT_TIMEOUT_SECONDS = 1;

    public function __construct(
        public int $timeoutMs = 3000,
        public int $breakerFailures = 5,
        public int $breakerOpenSeconds = 60,
    ) {}

    public static function load(): self
    {
        $values = DB::table('platform_settings')
            ->whereIn('key', ['ml.timeout_ms', 'ml.cb_failures', 'ml.cb_open_seconds'])
            ->pluck('value', 'key');
        $read = fn (string $key, int $default): int => isset($values[$key]) ? (int) json_decode((string) $values[$key]) : $default;

        return new self(
            timeoutMs: $read('ml.timeout_ms', 3000),
            breakerFailures: $read('ml.cb_failures', 5),
            breakerOpenSeconds: $read('ml.cb_open_seconds', 60),
        );
    }
}
