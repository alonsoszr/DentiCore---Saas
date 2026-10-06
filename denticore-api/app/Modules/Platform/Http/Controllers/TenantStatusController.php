<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Platform\Http\Resources\TenantResource;
use App\Modules\Platform\Services\TenantService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;

/**
 * Estado de una clínica (SDD §4.3.1; CUS-02, RF-019). La cancelación (RF-020) llega en MS-09.
 */
class TenantStatusController extends Controller
{
    public function __construct(private TenantService $tenants) {}

    #[ProblemResponse(409, 'La clínica no está activa (RF-019)')]
    public function suspend(Request $request, string $tenant): TenantResource
    {
        return TenantResource::make($this->tenants->suspend($this->tenants->find($tenant), $this->reason($request)));
    }

    #[ProblemResponse(409, 'La clínica no está suspendida (RF-019)')]
    public function reactivate(Request $request, string $tenant): TenantResource
    {
        return TenantResource::make($this->tenants->reactivate($this->tenants->find($tenant), $this->reason($request)));
    }

    /**
     * SDD §2.3: `status_reason` obligatorio, hasta 500 caracteres.
     */
    private function reason(Request $request): string
    {
        /** @var array{reason: string} $data */
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'motivo']);

        return $data['reason'];
    }
}
