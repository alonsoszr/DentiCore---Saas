<?php

/*
 * Verificación de seguridad de E1 (TASK-092; SDD §1.7, §3.6; RNF-091, RNF-093, RNF-094,
 * RNF-096): contraseñas con bcrypt de costo ≥ 12, tokens de alta entropía guardados solo como
 * hash, CORS sin comodín y datos sensibles cifrados con la clave de su clínica.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tokens\OneTimeTokenService;
use App\Support\Tokens\TokenPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('hashes passwords with bcrypt at cost 12 or more outside the test suite', function () {
    // La suite baja el costo a 4 por velocidad (phpunit.xml); producción usa el de .env.example o
    // el valor por defecto de Laravel.
    $example = (string) file_get_contents(base_path('.env.example'));
    preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', $example, $rounds);

    expect(config('hashing.driver'))->toBe('bcrypt')
        ->and((int) ($rounds[1] ?? 0))->toBeGreaterThanOrEqual(12)
        ->and(file_exists(config_path('hashing.php')))->toBeFalse()
        ->and(Hash::info(Hash::make('Clave-Segura-2026'))['algoName'])->toBe('bcrypt')
        ->and(Hash::driver('bcrypt')->make('x', ['rounds' => 12]))->toStartWith('$2y$12$');
})->group('RNF-093');

it('stores session and one time tokens only as hashes of at least 128 bits of entropy', function () {
    $tenant = Tenant::factory()->create(['slug' => 'clinica-tokens']);
    $user = User::factory()->for($tenant)->create(['role' => 'receptionist', 'email' => 'eva@tokens.test', 'password' => 'Clave-Segura-2026']);

    $plain = $this->postJson('/api/v1/auth/login', ['tenant_slug' => 'clinica-tokens', 'email' => 'eva@tokens.test', 'password' => 'Clave-Segura-2026'])
        ->assertOk()
        ->json('token');
    [, $secret] = explode('|', $plain, 2);

    // Sanctum: 40 caracteres alfanuméricos (≈ 238 bits) y en la base solo su SHA-256.
    expect(strlen($secret))->toBeGreaterThanOrEqual(40)
        ->and(DB::table('personal_access_tokens')->where('token', $secret)->exists())->toBeFalse()
        ->and(DB::table('personal_access_tokens')->where('token', hash('sha256', $secret))->exists())->toBeTrue();

    // Enlaces de un solo uso: 32 bytes aleatorios (256 bits) y en la base solo su hash.
    ['token' => $token] = TenantContext::run($tenant, fn () => app(OneTimeTokenService::class)->issue($user, TokenPurpose::PasswordReset, now()->addHour()));
    $stored = DB::table('one_time_tokens')->latest('id')->first();

    expect(strlen((string) base64_decode(strtr($token, '-_', '+/'), true)))->toBeGreaterThanOrEqual(16)
        ->and($stored->token_hash)->toBe(OneTimeTokenService::hash($token))
        ->and(json_encode($stored))->not->toContain($token);
})->group('RNF-094', 'DD-44');

it('allows only explicit CORS origins without wildcards', function () {
    // Cada origen es un patrón exacto (#^origen$#) armado con preg_quote; ningún comodín.
    $patterns = config('cors.allowed_origins_patterns');

    expect(config('cors.allowed_origins'))->toBe([])
        ->and($patterns)->not->toBeEmpty()
        ->and(collect($patterns)->every(fn (string $pattern) => preg_match('/^#\^[^*]+\$#$/', $pattern) === 1))->toBeTrue()
        ->and(collect($patterns)->contains(fn (string $pattern) => str_contains($pattern, '.*')))->toBeFalse()
        ->and(config('cors.supports_credentials'))->toBeFalse();

    $this->call('OPTIONS', '/api/v1/auth/me', server: ['HTTP_ORIGIN' => 'https://malicioso.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])
        ->assertHeaderMissing('Access-Control-Allow-Origin');
})->group('RNF-096');

it('encrypts the sensitive patient fields with the key of their clinic', function () {
    // SDD §2.5: documento, número de historia, teléfono y dirección van cifrados (DD-04). El
    // cifrado de volúmenes y respaldos es de infraestructura y se verifica en el entorno de MS-10.
    $tenant = Tenant::factory()->create();
    $patient = TenantContext::run($tenant, fn () => Patient::factory()->for($tenant)->create([
        'document_type' => 'dni', 'document_number' => '45678912', 'phone' => '987654321', 'address' => 'Av. Arequipa 1234',
    ]));

    $row = TenantContext::run($tenant, fn () => DB::table('patients')->where('id', $patient->id)->first());

    foreach (['document_number', 'clinical_record_number', 'phone', 'address'] as $column) {
        expect((string) $row->{$column})->toStartWith('v1:')
            ->not->toContain('45678912')->not->toContain('987654321')->not->toContain('Arequipa');
    }
})->group('RNF-091', 'DD-04');
