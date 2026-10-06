<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Resources\TenantResource;
use App\Modules\Platform\Services\TenantService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plan de una clínica (SDD §4.3.1; CUS-03, RF-022, RF-023).
 */
class TenantPlanController extends Controller
{
    public function __construct(private TenantService $tenants) {}

    #[ProblemResponse(422, 'El plan permite menos odontólogos que los activos (RF-022)')]
    public function update(Request $request, string $tenant): TenantResource
    {
        /** @var array{subscription_plan: string} $data */
        $data = $request->validate(
            ['subscription_plan' => ['required', 'string', Rule::exists('subscription_plans', 'code')]],
            [],
            ['subscription_plan' => 'plan'],
        );

        return TenantResource::make($this->tenants->changePlan($this->tenants->find($tenant), $data['subscription_plan']));
    }
}
