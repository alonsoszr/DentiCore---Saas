<?php

/*
 * Parámetros de la clínica (TASK-025; CUS-04; RF-024, RF-025, RF-026, RN-31, RN-35, RN-49).
 */

use App\Modules\Platform\Models\ClinicSetting;
use App\Modules\Platform\Models\Tenant;
use App\Support\Files\StoredFile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('shows the clinic data and parameters to its administrator', function () {
    $tenant = Tenant::factory()->create(['name' => 'Clínica Sonrisa', 'address' => 'Av. Arequipa 1234, Lima']);
    $this->actingAsRole('clinic_admin', $tenant);

    $this->getJson('/api/v1/clinic/settings')
        ->assertOk()
        ->assertJsonPath('data.name', 'Clínica Sonrisa')
        ->assertJsonPath('data.address', 'Av. Arequipa 1234, Lima')
        ->assertJsonPath('data.prices_include_igv', true)
        ->assertJsonPath('data.discount_cap_pct', '10.00')
        ->assertJsonPath('data.budget_validity_days', 30)
        ->assertJsonPath('data.portal_cancel_hours', 24)
        ->assertJsonPath('data.self_booking_enabled', false)
        ->assertJsonPath('data.logo', null);
})->group('CUS-04', 'RF-024');

it('updates the clinic data and parameters', function () {
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);

    $this->patchJson('/api/v1/clinic/settings', [
        'name' => 'Sonrisa Norte',
        'phone' => '014567890',
        'contact_email' => 'contacto@sonrisa.test',
        'prices_include_igv' => false,
        'discount_cap_pct' => '15.50',
        'budget_validity_days' => 60,
        'portal_cancel_hours' => 0,
        'self_booking_enabled' => true,
        'budget_terms' => 'El presupuesto no incluye radiografías.',
    ])->assertOk()->assertJsonPath('data.discount_cap_pct', '15.50');

    expect($tenant->fresh())->name->toBe('Sonrisa Norte')->phone->toBe('014567890')->contact_email->toBe('contacto@sonrisa.test');
    expect(TenantContext::run($tenant, fn () => ClinicSetting::query()->sole()))
        ->prices_include_igv->toBeFalse()
        ->budget_validity_days->toBe(60)
        ->portal_cancel_hours->toBe(0)
        ->self_booking_enabled->toBeTrue();
})->group('RF-024', 'RF-025', 'RF-026');

it('responds 422 on the field for values outside the CHECK limits', function (array $values, string $field) {
    $this->actingAsRole('clinic_admin', Tenant::factory()->create());

    $this->patchJson('/api/v1/clinic/settings', $values)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'descuento sobre 100 %' => [['discount_cap_pct' => '100.01'], 'discount_cap_pct'],
    'descuento negativo' => [['discount_cap_pct' => '-1'], 'discount_cap_pct'],
    'vigencia de 0 días' => [['budget_validity_days' => 0], 'budget_validity_days'],
    'vigencia de 181 días' => [['budget_validity_days' => 181], 'budget_validity_days'],
    'cancelación con 73 horas' => [['portal_cancel_hours' => 73], 'portal_cancel_hours'],
    'condiciones de 2001 caracteres' => [['budget_terms' => str_repeat('a', 2001)], 'budget_terms'],
    'IA antes de MS-12' => [['ai_enabled' => true], 'ai_enabled'],
    'libro de reclamaciones antes de MS-13' => [['complaints_book_url' => 'https://libro.test'], 'complaints_book_url'],
])->group('RN-31', 'RN-35', 'RN-49', 'RF-025', 'RF-026');

it('stores a PNG or JPG logo up to 1 MB pending the antivirus scan', function (string $name) {
    Storage::fake('s3');
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);

    $this->postJson('/api/v1/clinic/logo', ['logo' => UploadedFile::fake()->image($name)->size(1024)])
        ->assertOk()
        ->assertJsonPath('data.logo.status', 'pendiente');

    $logo = TenantContext::run($tenant, fn () => StoredFile::query()->sole());
    expect($tenant->fresh()->logo_file_id)->toBe($logo->id)
        ->and($logo->scan_status)->toBe('pendiente');
})->with(['logo.png', 'logo.jpg'])->group('RF-024');

it('rejects a logo above 1 MB or of another type', function (UploadedFile $file) {
    Storage::fake('s3');
    $tenant = Tenant::factory()->create();
    $this->actingAsRole('clinic_admin', $tenant);

    $this->postJson('/api/v1/clinic/logo', ['logo' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['logo']);

    expect($tenant->fresh()->logo_file_id)->toBeNull();
})->with([
    'PNG de 1,1 MB' => fn () => UploadedFile::fake()->image('logo.png')->size(1127),
    'GIF' => fn () => UploadedFile::fake()->image('logo.gif')->size(10),
    'PDF' => fn () => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
])->group('RF-024');

it('lets only clinic_admin access the clinic settings', function (string $role) {
    $this->actingAsRole($role, Tenant::factory()->create());

    $this->getJson('/api/v1/clinic/settings')->assertForbidden();
    $this->patchJson('/api/v1/clinic/settings', ['budget_validity_days' => 45])->assertForbidden();
    $this->postJson('/api/v1/clinic/logo')->assertForbidden();
})->with(['dentist', 'receptionist', 'patient', 'super_admin'])->group('CUS-04', 'RN-06');
