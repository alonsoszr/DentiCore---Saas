<?php

/*
 * Esquema de plataforma y clínicas (TASK-021; SDD §2.3, DD-16, DD-22, DD-23, DI-03, DI-04,
 * RF-013, RF-014, RNF-132). Las expectativas salen de las tablas y semillas de SDD §2.3.
 */

use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\SubscriptionPlan;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Platform\Services\TenantService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('seeds the three subscription plans of DD-16', function () {
    $plans = SubscriptionPlan::query()->orderBy('id')->get()->keyBy('code');

    expect($plans->keys()->all())->toBe(['basic', 'pro', 'enterprise']);

    expect($plans['basic'])
        ->max_dentists->toBe(2)
        ->includes_ai->toBeFalse()
        ->includes_risk->toBeFalse()
        ->includes_analytics->toBeFalse();
    expect($plans['pro'])
        ->max_dentists->toBe(10)
        ->includes_ai->toBeTrue()
        ->includes_risk->toBeTrue()
        ->includes_analytics->toBeFalse()
        ->ai_monthly_quota->toBe(300);
    expect($plans['enterprise'])
        ->max_dentists->toBeNull()
        ->includes_ai->toBeTrue()
        ->includes_risk->toBeTrue()
        ->includes_analytics->toBeTrue()
        ->ai_monthly_quota->toBe(1500);

    // PQ-01: sin precio definido; RNF-042: 1200 solicitudes por minuto por defecto.
    expect($plans->pluck('monthly_price_pen')->filter()->all())->toBe([]);
    expect($plans->pluck('rate_limit_per_minute')->unique()->all())->toBe([1200]);
})->group('DD-16', 'RN-08');

it('seeds the platform settings with a defined value', function () {
    $settings = DB::table('platform_settings')->pluck('value', 'key')
        ->map(fn (string $json): mixed => json_decode($json, true));

    expect($settings->all())->toMatchArray([
        'igv_rate' => 0.18,
        'ml.timeout_ms' => 3000,
        'ml.cb_failures' => 5,
        'ml.cb_open_seconds' => 60,
        'ai.timeout_s' => 15,
        'perf.error_rate_pct' => 1,
        'perf.ai_failure_pct' => 20,
        'perf.queue_wait_s' => 600,
        'perf.tenant_share_pct' => 50,
    ]);
})->group('RNF-131', 'RN-30');

it('creates a clinic with its plan as foreign key, its settings row and the Spanish status', function () {
    $tenant = app(TenantService::class)->create([
        'name' => 'Clínica Sonrisa', 'legal_name' => 'Clínica Sonrisa S.A.C.', 'ruc' => '20600000013',
        'slug' => 'clinica-sonrisa', 'address' => 'Av. Arequipa 1234, Lima', 'subscription_plan' => 'pro',
        'admin' => ['name' => 'Ana Admin', 'email' => 'ana@sonrisa.test'],
    ]);

    $tenant->refresh();
    expect($tenant->status)->toBe('activa')
        ->and($tenant->timezone)->toBe('America/Lima')
        ->and($tenant->plan->code)->toBe('pro');

    $settings = TenantContext::run($tenant, fn () => ClinicSetting::query()->sole());
    expect($settings)
        ->prices_include_igv->toBeTrue()
        ->discount_cap_pct->toBe('10.00')
        ->budget_validity_days->toBe(30)
        ->portal_cancel_hours->toBe(24)
        ->self_booking_enabled->toBeFalse()
        ->ai_enabled->toBeFalse();
})->group('RF-015', 'DD-16');

it('rejects changing the slug of a clinic', function () {
    $tenant = Tenant::factory()->create(['slug' => 'clinica-fija']);

    // Cada sentencia que debe fallar va en su propio punto de guardado (DB::transaction) para
    // que el error no anule la transacción de la prueba.
    expect(fn () => DB::transaction(fn () => DB::update(
        'UPDATE tenants SET slug = ? WHERE id = ?', ['otra-clinica', $tenant->id],
    )))->toThrow(QueryException::class, 'immutable_slug');

    // Cambiar otras columnas sigue permitido.
    DB::update('UPDATE tenants SET name = ? WHERE id = ?', ['Nuevo nombre', $tenant->id]);
    expect($tenant->fresh()->name)->toBe('Nuevo nombre');
})->group('DD-29', 'DD-03');

it('rejects a slug outside the format of SDD §2.3', function (string $slug) {
    expect(fn () => DB::transaction(fn () => Tenant::factory()->create(['slug' => $slug])))
        ->toThrow(QueryException::class, 'tenants_slug_format_check');
})->with([
    'con mayúsculas' => 'Clinica-Demo',
    'que termina en guion' => 'clinica-',
    'de 2 caracteres' => 'cd',
])->group('DD-29', 'DD-03');

it('rejects a RUC outside the format of SDD §2.3', function (string $ruc) {
    $tenant = Tenant::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('tenants')->where('id', $tenant->id)->update(['ruc' => $ruc])))
        ->toThrow(QueryException::class, 'tenants_ruc_format_check');
})->with([
    'que no empieza por 10 o 20' => '30123456789',
    'de 10 dígitos' => '2012345678',
])->group('RF-014');

it('keeps one settings row per clinic within the CHECK limits', function (array $values) {
    $tenant = Tenant::factory()->create();

    // El punto de guardado va dentro del contexto: TenantContext::run limpia app.tenant_id al
    // salir y eso no puede ejecutarse en una transacción ya anulada.
    expect(fn () => TenantContext::run($tenant, fn () => DB::transaction(
        fn () => ClinicSetting::query()->sole()->update($values),
    )))->toThrow(QueryException::class, 'check');
})->with([
    'descuento sobre 100 %' => [['discount_cap_pct' => 100.01]],
    'vigencia de 181 días' => [['budget_validity_days' => 181]],
    'vigencia de 0 días' => [['budget_validity_days' => 0]],
    'cancelación con 73 horas' => [['portal_cancel_hours' => 73]],
])->group('RN-31', 'RN-35', 'RN-49');

it('allows one document sequence per clinic and type', function () {
    $tenant = Tenant::factory()->create();

    TenantContext::run($tenant, function () use ($tenant): void {
        expect(DB::table('document_sequences')->orderBy('doc_type')->pluck('last_value', 'doc_type')->all())
            ->toBe(['presupuesto' => 0, 'recibo' => 0]);

        expect(fn () => DB::transaction(fn () => DB::table('document_sequences')->insert(['tenant_id' => $tenant->id, 'doc_type' => 'recibo'])))
            ->toThrow(QueryException::class, 'unique');
    });
})->group('DD-23', 'RN-42');
