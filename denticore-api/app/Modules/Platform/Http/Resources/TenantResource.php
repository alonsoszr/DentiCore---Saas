<?php

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Clínica para el Súper Administrador (CUS-01, CUS-02; RF-018).
 *
 * @mixin Tenant
 */
class TenantResource extends ApiResource
{
    private ?User $admin = null;

    /**
     * Agrega al primer administrador de la clínica (detalle y alta).
     */
    public function withAdmin(?User $admin): static
    {
        $this->admin = $admin;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'ruc' => $this->ruc,
            'slug' => $this->slug,
            'address' => $this->address,
            'phone' => $this->phone,
            'contact_email' => $this->contact_email,
            'plan' => SubscriptionPlanResource::make($this->whenLoaded('plan')),
            'status' => $this->status,
            'status_reason' => $this->status_reason,
            'timezone' => $this->timezone,
            /** @var int|null */
            'active_dentists' => $this->whenCounted('active_dentists_count', fn () => $this->getAttribute('active_dentists_count')),
            'admin' => $this->when($this->admin !== null, fn () => [
                'id' => $this->admin?->uuid,
                'name' => $this->admin?->name,
                'email' => $this->admin?->email,
                'status' => $this->admin?->status,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
