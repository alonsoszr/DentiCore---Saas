<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\AppointmentType;
use App\Support\Tenancy\TenantContext;

/**
 * Tipos de cita de la clínica (CUS-45; RF-145, RN-47): la validación de duración, color y nombre
 * único está en AppointmentTypeRequest y en las restricciones de la tabla.
 */
class AppointmentTypeService
{
    /**
     * @param  array{name: string, default_duration_min: int, color: string, portal_visible?: bool, is_active?: bool}  $data
     */
    public function create(array $data): AppointmentType
    {
        $type = (new AppointmentType)->forceFill([
            'tenant_id' => TenantContext::id(),
            'portal_visible' => false,
            'is_active' => true,
            ...$data,
        ]);
        $type->save();

        return $type;
    }

    /**
     * @param  array{name?: string, default_duration_min?: int, color?: string, portal_visible?: bool, is_active?: bool}  $data
     */
    public function update(AppointmentType $type, array $data): AppointmentType
    {
        $type->forceFill($data)->save();

        return $type;
    }
}
