<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * patient_uuid (extensión confirmada con el usuario) solo aparece para cuentas de
     * portal con la relación cargada: es la ficha que el paciente puede consultar, o null
     * si aún no está vinculado.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->is_active,
            'tenant' => TenantResource::make($this->whenLoaded('tenant')),
            'patient_uuid' => $this->when(
                $this->role === 'patient' && $this->relationLoaded('patient'),
                fn () => $this->patient?->uuid,
            ),
        ];
    }
}
