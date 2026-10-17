<?php

namespace App\Support\Http;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Throwable;

/**
 * Procesador de registros de SDD §1.7 (RNF-110): elimina del contexto y de `extra` las claves
 * `document*`, `phone`, `email`, `address`, `note`, `token` y `password`, también anidadas, y
 * enmascara en el mensaje, en los valores de texto y en las excepciones (cuyo mensaje puede traer
 * los valores de una consulta) los documentos de 8 dígitos, los celulares y los correos. Se aplica
 * con `tap` en todos los canales de config/logging.php.
 */
class RedactPersonalData
{
    private const PERSONAL_KEYS = '/^(document|phone|email|address|note|token|password)/i';

    /**
     * Patrones de datos personales en texto libre. Los límites excluyen el guion para no tocar los
     * bloques de un UUID (el correlation_id).
     */
    private const PERSONAL_TEXT = [
        '/[\w.+-]+@[\w-]+(\.[\w-]+)+/u' => '[correo]',
        '/(?<![\w-])9\d{8}(?![\w-])/' => '[celular]',
        '/(?<![\w-])\d{8}(?![\w-])/' => '[documento]',
    ];

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! $monolog instanceof MonologLogger) {
            return;
        }

        $monolog->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
            message: self::redactText($record->message),
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

            $clean[$key] = match (true) {
                is_array($value) => self::redact($value),
                is_string($value) => self::redactText($value),
                $value instanceof Throwable => self::redactException($value),
                default => $value,
            };
        }

        return $clean;
    }

    public static function redactText(string $text): string
    {
        return (string) preg_replace(array_keys(self::PERSONAL_TEXT), array_values(self::PERSONAL_TEXT), $text);
    }

    /**
     * La excepción se registra sin su mensaje original ni los argumentos de su traza: solo la
     * clase, el mensaje enmascarado y los marcos (archivo, línea y función).
     *
     * @return array{class: class-string<Throwable>, message: string, file: string, line: int, trace: list<string>}
     */
    private static function redactException(Throwable $exception): array
    {
        return [
            'class' => $exception::class,
            'message' => self::redactText($exception->getMessage()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => array_map(
                fn (array $frame): string => ($frame['file'] ?? '[interno]').':'.($frame['line'] ?? 0).' '.($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'],
                array_slice($exception->getTrace(), 0, 20),
            ),
        ];
    }
}
