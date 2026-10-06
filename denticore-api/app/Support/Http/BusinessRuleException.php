<?php

namespace App\Support\Http;

use RuntimeException;

/**
 * Regla de negocio incumplida (SDD §4.1): se responde como problem+json con el código de la
 * regla (`rule`, p. ej. "RN-10") y, si corresponde, errores por campo. 422 por defecto; 409
 * para conflictos de estado o concurrencia.
 */
class BusinessRuleException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, scalar|null>  $extensions  Miembros adicionales del problem+json (RFC 9457 §3.2).
     */
    public function __construct(
        public readonly string $rule,
        string $detail,
        public readonly array $errors = [],
        public readonly int $status = 422,
        public readonly array $extensions = [],
    ) {
        parent::__construct($detail);
    }
}
