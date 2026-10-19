<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Fila de la bitácora de una clínica (SDD §2.12 `audit_logs`; CUS-66, RF-187): quién, qué, sobre
 * qué recurso y paciente, y desde dónde. Nunca lleva datos clínicos ni de identificación (RN-67).
 *
 * @mixin AuditLog
 */
class AuditLogResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        /** @var User|null $user */
        $user = $this->resource->getRelationValue('user');

        return [
            'created_at' => $this->created_at,
            'action' => $this->action,
            'actor_role' => $this->actor_role,
            'user' => $user === null ? null : ['id' => $user->uuid, 'name' => $user->name],
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_uuid,
            'patient_id' => $this->patient_uuid,
            'changed_fields' => $this->changed_fields,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'correlation_id' => $this->correlation_id,
        ];
    }
}
