<?php

namespace App\Modules\Risk\Services;

use RuntimeException;

/**
 * El motor ML no dio una predicción válida (RN-64, CA-55.3): timeout, error, respuesta inválida o
 * Circuit Breaker abierto. La API responde «Predicción no disponible» y no guarda nada.
 * `outcome` usa los valores de `external_call_logs.outcome`.
 */
class RiskEngineUnavailable extends RuntimeException
{
    public function __construct(public readonly string $outcome, public readonly ?int $httpStatus = null)
    {
        parent::__construct("Motor ML no disponible ({$outcome}).");
    }
}
