<?php

namespace App\Support\Http;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

/**
 * Procesador de registros de SDD §1.7 (RNF-110): elimina del contexto y de `extra` las claves
 * `document*`, `phone`, `email`, `address`, `note`, `token` y `password`, también anidadas.
 * Se aplica con `tap` en los canales de config/logging.php.
 */
class RedactPersonalData
{
    private const PERSONAL_KEYS = '/^(document|phone|email|address|note|token|password)/i';

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            context: self::redact($record->context),
            extra: self::redact($record->extra),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PERSONAL_KEYS, $key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}
