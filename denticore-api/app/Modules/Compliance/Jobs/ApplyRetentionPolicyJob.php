<?php

namespace App\Modules\Compliance\Jobs;

use App\Modules\Compliance\Services\RetentionService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Aplica la retención de una sola clínica en su contexto (SDD §4.4; RF-190). */
class ApplyRetentionPolicyJob implements ShouldQueue
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

    public function handle(RetentionService $retention): void
    {
        $retention->apply(Tenant::query()->findOrFail($this->tenantId));
    }
}
