<?php

/*
 * T-013 (SDD §6.3.1; RN-02, RF-002).
 */

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Tests\Concerns\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns zero rows from a tenant model without a resolved tenant', function () {
    Patient::factory()->for(Tenant::factory())->count(2)->create();

    expect(TenantContext::id())->toBeNull()
        ->and(Patient::query()->count())->toBe(0);
})->group('RN-02', 'RF-002');

it('returns only the rows of the resolved tenant', function () {
    $tenant = Tenant::factory()->create();
    Patient::factory()->for($tenant)->create();
    Patient::factory()->for(Tenant::factory())->create();

    TenantContext::run($tenant, function () use ($tenant) {
        expect(Patient::query()->pluck('tenant_id')->all())->toBe([$tenant->id]);
    });

    expect(TenantContext::id())->toBeNull();
})->group('RN-02', 'RF-002');
