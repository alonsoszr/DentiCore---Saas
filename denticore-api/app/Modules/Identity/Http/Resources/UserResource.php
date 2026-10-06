<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Http\Resources\TenantResource;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin User
 */
class UserResource extends ApiResource
{
    /**
     * patient_uuid (extensión confirmada con el usuario) solo aparece para cuentas de
     * portal con la relación cargada: es la ficha que el paciente puede consultar, o null
     * si aún no está vinculado.
     *
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
            'is_data_officer' => $this->is_data_officer,
            'cop_number' => $this->cop_number,
            'specialty' => $this->specialty,
            'rne_number' => $this->rne_number,
            'last_login_at' => $this->last_login_at,
            'tenant' => TenantResource::make($this->whenLoaded('tenant')),
            'patient_uuid' => $this->when(
                $this->role === 'patient' && $this->relationLoaded('patient'),
                fn () => $this->patient?->uuid,
            ),
        ];
    }
}
