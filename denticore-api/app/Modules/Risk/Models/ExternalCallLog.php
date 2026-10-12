<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro de una llamada a un servicio externo (SDD §2.12 `external_call_logs`; RN-64, RF-197,
 * RNF-172). Tabla de M13 adelantada a MS-05 porque RN-64 exige registrar los fallos del motor ML
 * (Plan §8.4). `tenant_id` es nulable (excepción de DD-03, sin Global Scope ni RLS).
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $service
 * @property string $operation
 * @property string $outcome
 * @property int $latency_ms
 * @property int|null $http_status
 * @property string $breaker_state
 * @property string $correlation_id
 * @property Carbon $created_at
 */
class ExternalCallLog extends Model
{
    protected function casts(): array
    {
        return [
            'latency_ms' => 'integer',
            'http_status' => 'integer',
        ];
    }
}
