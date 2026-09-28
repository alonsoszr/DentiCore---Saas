<?php

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Models\Tenant;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * @mixin Tenant
 */
class TenantResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'subscription_plan' => $this->subscription_plan,
            'status' => $this->status,
            /** @var array<string, mixed>|null */
            'settings' => $this->settings,
            'created_at' => $this->created_at,
        ];
    }
}
