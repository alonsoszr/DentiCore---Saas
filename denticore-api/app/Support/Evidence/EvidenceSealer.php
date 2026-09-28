<?php

namespace App\Support\Evidence;

use RuntimeException;

/**
 * Sellado de evidencias (SDD §1.7; DD-46, RNF-113): HMAC-SHA256 con `EVIDENCE_HMAC_KEY`
 * sobre JSON canónico (claves ordenadas, UTF-8). Se usa en consentimientos, decisiones de
 * presupuesto, cierres de atención y consentimientos informados.
 */
class EvidenceSealer
{
    public function __construct(private string $key) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function seal(array $payload): string
    {
        return hash_hmac('sha256', self::canonicalJson($payload), $this->key());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verify(array $payload, string $seal): bool
    {
        return hash_equals($this->seal($payload), $seal);
    }

    /**
     * JSON con las claves ordenadas en todos los niveles, sin escapar Unicode ni barras.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function canonicalJson(array $payload): string
    {
        return json_encode(self::sortKeys($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return array_map(fn (mixed $value): mixed => is_array($value) ? self::sortKeys($value) : $value, $data);
    }

    private function key(): string
    {
        if ($this->key === '') {
            throw new RuntimeException('Falta EVIDENCE_HMAC_KEY para sellar evidencias.');
        }

        return $this->key;
    }
}
