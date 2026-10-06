<?php

/*
 * TenantContext, BelongsToTenant, ResolveTenant y TenantAwareJob (TASK-005; SDD §1.6.2,
 * §1.6.3, §5.15; RN-01, RN-02, DI-10).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\MissingTenantContextException;
use App\Support\Tenancy\ResolveTenant;
use App\Support\Tenancy\TenantAwareJob;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantMutationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\DB;

/** Job de clínica de prueba: cuenta los pacientes visibles en su contexto. */
class CountPatientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public static ?int $seen = null;

    public static ?string $databaseTenant = null;

    public function __construct(public ?int $tenantId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new TenantAwareJob];
    }

    public function handle(): void
    {
        self::$seen = Patient::query()->count();
        self::$databaseTenant = databaseTenantSetting();
    }
}

function databaseTenantSetting(): ?string
{
    return DB::selectOne("select current_setting('app.tenant_id', true) as value")->value;
}

beforeEach(function () {
    CountPatientsJob::$seen = null;
    CountPatientsJob::$databaseTenant = null;
});

it('runs a clinic job inside its clinic and clears the context afterwards', function () {
    $tenant = Tenant::factory()->create();
    Patient::factory()->for($tenant)->count(2)->create();
    Patient::factory()->for(Tenant::factory())->create();

    CountPatientsJob::dispatch($tenant->id);

    expect(CountPatientsJob::$seen)->toBe(2)
        ->and(CountPatientsJob::$databaseTenant)->toBe((string) $tenant->id)
        ->and(TenantContext::id())->toBeNull()
        ->and(databaseTenantSetting())->toBe('');
})->group('RN-01', 'RNF-101');

it('fails a clinic job dispatched without tenant_id without reading or writing rows', function () {
    Patient::factory()->for(Tenant::factory())->create();

    expect(fn () => CountPatientsJob::dispatch(null))->toThrow(MissingTenantContextException::class)
        ->and(CountPatientsJob::$seen)->toBeNull();
})->group('RN-02', 'RNF-101');

it('throws TenantMutationException when a tenant_id is changed', function () {
    $tenant = Tenant::factory()->create();
    $other = Tenant::factory()->create();
    $patient = Patient::factory()->for($tenant)->create();

    TenantContext::run($tenant, function () use ($patient, $other) {
        $patient->tenant_id = $other->id;

        expect(fn () => $patient->save())->toThrow(TenantMutationException::class);
    });
})->group('RN-01');

it('refuses to create a clinic record without a resolved clinic', function () {
    expect(fn () => Patient::query()->create([
        'document_number' => '12345678',
        'first_name' => 'Ana',
        'last_name' => 'Quispe',
        'birth_date' => '1990-01-01',
    ]))->toThrow(RuntimeException::class)
        ->and(Patient::withoutTenantScope()->count())->toBe(0);
})->group('RN-02');

it('runs ResolveTenant before SubstituteBindings', function () {
    $priority = app(Kernel::class)->getMiddlewarePriority();

    expect(array_search(ResolveTenant::class, $priority, true))
        ->toBeLessThan(array_search(SubstituteBindings::class, $priority, true));
})->group('DD-03');

it('clears app.tenant_id after a clinic request and forbids clinic routes to users without clinic', function () {
    $tenant = Tenant::factory()->create();
    $dentist = User::factory()->for($tenant)->create(['role' => 'dentist']);

    $this->actingWithToken($dentist)->getJson('/api/v1/patients')->assertOk();
    expect(databaseTenantSetting())->toBe('')
        ->and(TenantContext::id())->toBeNull();

    $superAdmin = User::factory()->superAdmin()->create();
    $this->actingWithToken($superAdmin)->getJson('/api/v1/patients')->assertForbidden();
})->group('DI-10', 'RF-005');
