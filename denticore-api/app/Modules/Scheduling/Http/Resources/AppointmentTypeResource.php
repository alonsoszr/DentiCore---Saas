<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\AppointmentType;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Tipo de cita (CUS-45; RF-145): duración por defecto, color, visibilidad en el portal y estado.
 *
 * @mixin AppointmentType
 */
class AppointmentTypeResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'name' => $this->name,
            'default_duration_min' => $this->default_duration_min,
            'color' => $this->color,
            'portal_visible' => $this->portal_visible,
            'is_active' => $this->is_active,
        ];
    }
}
