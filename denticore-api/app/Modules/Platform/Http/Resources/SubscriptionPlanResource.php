<?php

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Models\SubscriptionPlan;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Plan de suscripción (DD-16; RF-022). `max_dentists` nulo = ilimitado.
 *
 * @mixin SubscriptionPlan
 */
class SubscriptionPlanResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'max_dentists' => $this->max_dentists,
            'includes_ai' => $this->includes_ai,
            'includes_risk' => $this->includes_risk,
            'includes_analytics' => $this->includes_analytics,
        ];
    }
}
