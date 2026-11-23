<?php

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\ReferenceData\ReferenceDatasetGenerator;
use App\Support\ReferenceData\ReferenceDatasetPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('generates and resumes a synthetic dataset with the documented ratios per clinic', function () {
    $this->travelTo(now()->startOfYear()->addMonths(10));
    $plan = new ReferenceDatasetPlan(42, [2, 251]);
    $generator = app(ReferenceDatasetGenerator::class);
    $password = Str::password(24);

    $generator->generate($plan, $password);
    $generator->generate($plan, $password);

    expect(Tenant::query()->whereIn('slug', [$plan->slug(1), $plan->slug(2)])->count())->toBe(2);

    foreach ($plan->clinicSizes() as $offset => $patients) {
        $tenant = Tenant::query()->where('slug', $plan->slug($offset + 1))->sole();

        TenantContext::run($tenant, function () use ($patients, $tenant) {
            expect(Patient::query()->count())->toBe($patients)
                ->and(Patient::query()->firstOrFail()->document_number)->toMatch('/^CDR[A-Z0-9]+$/')
                ->and(DB::table('attentions')->where('tenant_id', $tenant->id)->count())->toBe($patients * 8)
                ->and(DB::table('odontogram_entries')->where('tenant_id', $tenant->id)->count())->toBe($patients * 25)
                ->and(DB::table('appointments')->where('tenant_id', $tenant->id)->count())->toBe($patients * 10)
                ->and(DB::table('budgets')->where('tenant_id', $tenant->id)->count())->toBe($patients * 2)
                ->and(DB::table('budget_lines')->where('tenant_id', $tenant->id)->count())->toBe($patients * 8);
        });
    }
})->group('TASK-072', 'RNF-037', 'RES-08');

it('rolls back an interrupted batch and can load it again', function () {
    $this->travelTo(now()->startOfYear()->addMonths(10));
    $plan = new ReferenceDatasetPlan(43, [2]);
    $generator = app(ReferenceDatasetGenerator::class);
    $password = Str::password(24);
    $interrupt = true;
    DB::listen(function (QueryExecuted $query) use (&$interrupt): void {
        if ($interrupt && str_starts_with($query->sql, 'insert into "patients"')) {
            $interrupt = false;
            throw new RuntimeException('Interrupción sintética del lote.');
        }
    });

    expect(fn () => $generator->generate($plan, $password))
        ->toThrow(RuntimeException::class, 'Interrupción sintética del lote.');
    $tenant = Tenant::query()->where('slug', $plan->slug(1))->sole();
    expect(TenantContext::run($tenant, fn () => Patient::query()->count()))->toBe(0);

    $generator->generate($plan, $password);
    expect(TenantContext::run($tenant, fn () => Patient::query()->count()))->toBe(2);
})->group('TASK-072', 'RNF-037', 'RES-08');

it('refuses to load the reference dataset outside staging', function () {
    $this->artisan('cdr:generate', ['--seed' => 42])
        ->expectsOutput('El conjunto de referencia solo se genera en staging.')
        ->assertFailed();
})->group('TASK-072', 'RES-08');
