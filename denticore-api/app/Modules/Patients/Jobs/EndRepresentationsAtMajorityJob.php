<?php

namespace App\Modules\Patients\Jobs;

use App\Modules\Patients\Services\LegalRepresentativeService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Termina las representaciones de los pacientes de una clínica que cumplen 18 años (SDD §4.4;
 * RF-061, RN-13). Corre en el contexto de la clínica (TenantAwareJob).
 */
class EndRepresentationsAtMajorityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public ?int $tenantId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(LegalRepresentativeService $representatives): void
    {
        $representatives->endAtMajority(Tenant::query()->findOrFail($this->tenantId));
    }
}
