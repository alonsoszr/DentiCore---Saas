<?php

namespace App\Support\Encryption;

/**
 * Índice ciego (SDD §1.7.1): HMAC-SHA256 del valor con la clave `bidx` de la clínica. Permite
 * unicidad y búsqueda exacta sobre un campo cifrado, cuyo texto cifrado cambia en cada
 * cifrado. El mismo valor produce índices distintos en clínicas distintas.
 */
class BlindIndex
{
    public function __construct(private KeyRing $keys) {}

    /**
     * El valor se normaliza con trim(); la normalización `TIPO:NUMERO` de RN-09 la agrega
     * el registro de pacientes (TASK-031/TASK-032).
     */
    public function compute(int $tenantId, string $value): string
    {
        $key = $this->keys->blindIndexKey($tenantId, $this->keys->activeVersion($tenantId));

        return hash_hmac('sha256', trim($value), $key);
    }
}
