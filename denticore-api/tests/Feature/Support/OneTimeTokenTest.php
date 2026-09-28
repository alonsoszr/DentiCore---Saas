<?php

/*
 * Tokens de un solo uso y resolución pública de la clínica (TASK-015; SDD §1.6.2, §2.4, §4.2;
 * DI-15, DD-15, DD-22, DD-29, RNF-111, RNF-112).
 */

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeToken;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'tenant.token:invitacion'])->get('/api/v1/_prueba/invitaciones/{token}', fn () => response()->json([
        'tenant' => TenantContext::tenant()?->uuid,
        'user' => request()->attributes->get('one_time_token')->tokenable->uuid,
    ]));

    Route::middleware(['api', 'tenant.token:invitacion', 'throttle:codes'])
        ->post('/api/v1/_prueba/invitaciones/{token}/codigo', function () {
            $record = request()->attributes->get('one_time_token');

            if (request('codigo') !== '123456') {
                app(OneTimeTokenService::class)->registerFailure($record);
                abort(422);
            }

            app(OneTimeTokenService::class)->consume($record);

            return response()->json(['ok' => true]);
        })->name('prueba.codigo');

    Route::middleware(['api', 'tenant.slug'])->get('/api/v1/_prueba/clinicas/{slug}', fn () => response()->json([
        'tenant' => TenantContext::tenant()?->uuid,
    ]));

    Route::middleware(['api', 'tenant.slug:login'])->post('/api/v1/_prueba/ingreso', fn () => response()->json([
        'tenant' => TenantContext::tenant()?->uuid,
    ]));
});

/**
 * @return array{token: string, record: OneTimeToken, tenant: Tenant, user: User}
 */
function invitation(?DateTimeInterface $expiresAt = null): array
{
    $tenant = Tenant::factory()->create();
    $user = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
    $issued = TenantContext::run($tenant, fn () => app(OneTimeTokenService::class)->issue($user, TokenPurpose::Invitation, $expiresAt ?? now()->addHours(72)));

    return [...$issued, 'tenant' => $tenant, 'user' => $user];
}

it('resolves the clinic from a valid token before loading the resource', function () {
    ['token' => $token, 'tenant' => $tenant, 'user' => $user] = invitation();

    expect(strlen($token))->toBeGreaterThanOrEqual(22);
    $this->getJson("/api/v1/_prueba/invitaciones/{$token}")
        ->assertOk()
        ->assertJson(['tenant' => $tenant->uuid, 'user' => $user->uuid]);

    expect(TenantContext::id())->toBeNull();
})->group('DI-15', 'DD-22');

it('answers the same 404 for an expired, used or unknown token', function () {
    $expired = invitation(now()->addMinutes(5));
    $used = invitation();
    app(OneTimeTokenService::class)->consume($used['record']);
    $this->travel(10)->minutes();

    $bodies = collect([$expired['token'], $used['token'], 'token-que-no-existe-0123456789abcdef'])
        ->map(function (string $token) {
            $response = $this->getJson("/api/v1/_prueba/invitaciones/{$token}")->assertNotFound();

            return collect($response->json())->except('instance')->all();
        });

    expect($bodies->unique(fn ($body) => json_encode($body)))->toHaveCount(1);
})->group('RNF-112');

it('invalidates a token after 5 failed attempts', function () {
    ['token' => $token, 'record' => $record] = invitation();
    $service = app(OneTimeTokenService::class);

    foreach (range(1, 4) as $attempt) {
        $service->registerFailure($record);
        expect($service->find($token, TokenPurpose::Invitation))->not->toBeNull("intento {$attempt}");
    }

    $service->registerFailure($record);

    expect($service->find($token, TokenPurpose::Invitation))->toBeNull()
        ->and($record->fresh()->invalidated_at)->not->toBeNull();
    $this->getJson("/api/v1/_prueba/invitaciones/{$token}")->assertNotFound();
})->group('RNF-111');

it('limits failed code attempts with throttle:codes and consumes the token once', function () {
    ['token' => $token] = invitation();
    $url = "/api/v1/_prueba/invitaciones/{$token}/codigo";

    foreach (range(1, 4) as $attempt) {
        $this->postJson($url, ['codigo' => '000000'])->assertUnprocessable();
    }

    $this->postJson($url, ['codigo' => '123456'])->assertOk();
    // Usado: ya no se puede reutilizar.
    $this->postJson($url, ['codigo' => '123456'])->assertNotFound();

    ['token' => $other] = invitation();
    $otherUrl = "/api/v1/_prueba/invitaciones/{$other}/codigo";
    foreach (range(1, 5) as $attempt) {
        $this->postJson($otherUrl, ['codigo' => '000000'])->assertUnprocessable();
    }
    $this->postJson($otherUrl, ['codigo' => '123456'])->assertStatus(429)->assertHeader('Retry-After');
})->group('RNF-111');

it('stores only the SHA-256 of the token', function () {
    ['token' => $token, 'record' => $record] = invitation();

    $row = (array) DB::table('one_time_tokens')->where('id', $record->id)->first();

    expect($row['token_hash'])->toBe(hash('sha256', $token))
        ->and(json_encode($row))->not->toContain($token)
        ->and($record->toArray())->not->toHaveKey('token_hash');
})->group('RNF-112');

it('keeps a shared budget link reusable and invalidates previous invitations', function () {
    ['user' => $user, 'tenant' => $tenant, 'token' => $first] = invitation();
    $service = app(OneTimeTokenService::class);

    $shared = TenantContext::run($tenant, fn () => $service->issue($user, TokenPurpose::SharedBudget, now()->addDays(30)));
    $service->consume($shared['record']);
    expect($service->find($shared['token'], TokenPurpose::SharedBudget))->not->toBeNull();

    expect($service->invalidateFor($user, TokenPurpose::Invitation))->toBe(1)
        ->and($service->find($first, TokenPurpose::Invitation))->toBeNull()
        ->and($service->find($shared['token'], TokenPurpose::Invitation))->toBeNull();
})->group('RF-131', 'RF-016');

it('resolves the clinic by its access code and hides deleted or unknown clinics', function () {
    $tenant = Tenant::factory()->create(['slug' => 'clinica-publica']);

    $this->getJson('/api/v1/_prueba/clinicas/clinica-publica')->assertOk()->assertJson(['tenant' => $tenant->uuid]);
    $this->getJson('/api/v1/_prueba/clinicas/no-existe')->assertNotFound();

    $this->postJson('/api/v1/_prueba/ingreso', ['tenant_slug' => 'clinica-publica'])->assertJson(['tenant' => $tenant->uuid]);
    $this->postJson('/api/v1/_prueba/ingreso', ['tenant_slug' => 'no-existe'])
        ->assertUnauthorized()
        ->assertJsonPath('detail', 'Credenciales inválidas.');
    // Sin código: ingreso de super_admin, sin clínica.
    $this->postJson('/api/v1/_prueba/ingreso', [])->assertOk()->assertJson(['tenant' => null]);
})->group('DD-29', 'RF-033');
