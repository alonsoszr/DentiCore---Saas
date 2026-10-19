<?php

namespace App\Modules\Compliance\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Audit\AuditLog;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Fila de la bitácora para el Súper Administrador (CUS-66, RF-187, RN-04): los metadatos del
 * evento y su clínica (null en los eventos de plataforma), sin el paciente afectado.
 *
 * @mixin AuditLog
 */
class PlatformAuditLogResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        /** @var User|null $user */
        $user = $this->resource->getRelationValue('user');
        /** @var Tenant|null $clinic */
        $clinic = $this->resource->getRelationValue('clinic');

        return [
            'created_at' => $this->created_at,
            'clinic' => $clinic === null ? null : ['id' => $clinic->uuid, 'name' => $clinic->name],
            'action' => $this->action,
            'actor_role' => $this->actor_role,
            'user' => $user === null ? null : ['id' => $user->uuid, 'name' => $user->name],
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_uuid,
            'changed_fields' => $this->changed_fields,
            'metadata' => $this->metadata,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'correlation_id' => $this->correlation_id,
        ];
    }
}
