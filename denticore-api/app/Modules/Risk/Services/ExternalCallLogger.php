<?php

namespace App\Modules\Risk\Services;

use App\Modules\Risk\Models\ExternalCallLog;
use App\Support\Tenancy\TenantContext;

/**
 * Registra cada llamada a un servicio externo en `external_call_logs` (SDD §2.12; RN-64, RF-197).
 * No guarda la solicitud ni la respuesta: solo el resultado, la latencia, el estado HTTP y el
 * del Circuit Breaker.
 */
class ExternalCallLogger
{
    public function record(
        string $service,
        string $operation,
        string $outcome,
        int $latencyMs,
        ?int $httpStatus,
        string $breakerState,
        string $correlationId,
    ): void {
        ExternalCallLog::query()->forceCreate([
            'tenant_id' => TenantContext::id(),
            'service' => $service,
            'operation' => $operation,
            'outcome' => $outcome,
            'latency_ms' => max(0, $latencyMs),
            'http_status' => $httpStatus,
            'breaker_state' => $breakerState,
            'correlation_id' => $correlationId,
        ]);
    }
}
