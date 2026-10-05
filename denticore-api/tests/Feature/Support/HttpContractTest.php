<?php

/*
 * Errores, recursos, correlación y cabeceras de la API (TASK-008; SDD §1.7, §4.1; RF-007,
 * RF-008, DD-19, DD-44, RNF-096, RNF-110, RNF-125). T-027, T-028 y T-164 de SDD §6.3.
 */

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Http\BusinessRuleException;
use App\Support\Http\RedactPersonalData;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function assertProblem(TestResponse $response, int $status): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance'])
        ->assertJsonPath('status', $status);

    expect($response->json('type'))->toStartWith('https://denticore.pe/problems/')
        ->and($response->json('instance'))->toBe('urn:correlation:'.$response->headers->get('X-Correlation-Id'))
        ->and($response->getContent())->not->toContain('vendor')
        ->and($response->getContent())->not->toContain('SQLSTATE');
}

it('renders 401, 403, 404, 409, 422 and 429 as problem+json in Spanish', function () {
    Route::middleware('api')->get('/api/v1/_prueba/conflicto', fn () => throw new ConflictHttpException);
    Route::middleware('api')->get('/api/v1/_prueba/regla', fn () => throw new BusinessRuleException(
        'RN-10',
        'El paciente no tiene un consentimiento vigente con la finalidad de atención odontológica.',
        ['patient' => ['Registre el consentimiento de datos antes de registrar datos clínicos.']],
    ));

    assertProblem($unauthenticated = $this->getJson('/api/v1/auth/me'), 401);
    expect($unauthenticated->json('title'))->toBe('No autenticado');

    $tenant = Tenant::factory()->create();
    $this->actingAsRole('dentist', $tenant);
    assertProblem($forbidden = $this->getJson('/api/v1/users'), 403);
    expect($forbidden->json('detail'))->toBe('No tiene permiso para realizar esta acción.');

    $other = Patient::factory()->for(Tenant::factory())->create();
    assertProblem($this->getJson("/api/v1/patients/{$other->uuid}"), 404);

    assertProblem($conflict = $this->getJson('/api/v1/_prueba/conflicto'), 409);
    expect($conflict->json('title'))->toBe('Conflicto');

    assertProblem($invalid = $this->postJson('/api/v1/patients', []), 422);
    expect($invalid->json('errors.first_name.0'))->toBe('El campo nombres es obligatorio.');

    assertProblem($rule = $this->getJson('/api/v1/_prueba/regla'), 422);
    expect($rule->json('rule'))->toBe('RN-10')
        ->and($rule->json('type'))->toBe('https://denticore.pe/problems/business-rule')
        ->and($rule->json('errors.patient.0'))->toContain('consentimiento');

    for ($request = 0; $request < 60; $request++) {
        $this->getJson('/api/v1/auth/me');
    }
    assertProblem($limited = $this->getJson('/api/v1/auth/me'), 429);
    expect($limited->headers->get('Retry-After'))->not->toBeNull();
})->group('RF-008', 'DD-19');

it('exposes no numeric id in any API response', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->for($tenant)->create(['role' => 'clinic_admin']);
    $portal = User::factory()->for($tenant)->create(['role' => 'patient']);
    $patient = Patient::factory()->for($tenant)->create();

    $responses = [
        $this->actingWithToken($superAdmin)->getJson('/api/v1/platform/tenants'),
        $this->actingWithToken($admin)->getJson('/api/v1/auth/me'),
        $this->actingWithToken($admin)->getJson('/api/v1/users'),
        $this->actingWithToken($admin)->getJson('/api/v1/patients'),
        $this->actingWithToken($admin)->getJson("/api/v1/patients/{$patient->uuid}"),
        $this->actingWithToken($admin)->postJson('/api/v1/patients', [
            'document_id' => '70000009', 'first_name' => 'Ana', 'last_name' => 'Quispe',
            'birth_date' => '1990-01-01', 'user_uuid' => $portal->uuid,
        ]),
    ];

    $numericIds = function (mixed $data, string $path = '') use (&$numericIds): array {
        if (! is_array($data)) {
            return [];
        }

        $found = [];
        foreach ($data as $key => $value) {
            $isIdKey = $key === 'id' || str_ends_with((string) $key, '_id');
            if ($isIdKey && is_int($value)) {
                $found[] = "{$path}.{$key}";
            }
            $found = [...$found, ...$numericIds($value, "{$path}.{$key}")];
        }

        return $found;
    };

    foreach ($responses as $response) {
        $response->assertSuccessful();
        expect($numericIds($response->json()))->toBe([]);
    }

    expect($responses[4]->json('data.id'))->toBe($patient->uuid);
})->group('RF-007', 'DD-19');

it('finds no personal data patterns in logs of a synthetic run', function () {
    $logFile = storage_path('logs/privacidad-'.uniqid().'.log');
    config(['logging.channels.privacy_probe' => [
        'driver' => 'single',
        'path' => $logFile,
        'tap' => [RedactPersonalData::class],
    ]]);
    config(['logging.default' => 'privacy_probe']);
    Log::forgetChannel('privacy_probe');

    $tenant = Tenant::factory()->create();
    $this->actingAsRole('receptionist', $tenant);
    $this->postJson('/api/v1/patients', [
        'document_id' => '45678912', 'first_name' => 'Rosa', 'last_name' => 'Quispe',
        'birth_date' => '1990-05-10', 'phone' => '987654321', 'email' => 'rosa@correo.test',
    ])->assertCreated();

    // Un registro que por error incluye datos personales en el contexto.
    Log::warning('prueba.privacidad', [
        'document_number' => '45678912',
        'phone' => '987654321',
        'email' => 'rosa@correo.test',
        'patient' => ['address' => 'Av. Siempre Viva 123', 'phone_alt' => '912345678'],
        'token' => 'secreto',
        'estado' => 'ok',
    ]);

    $contents = file_get_contents($logFile);
    @unlink($logFile);

    expect($contents)->toContain('prueba.privacidad')
        ->and($contents)->toContain('"estado":"ok"')
        ->and($contents)->toContain('correlation_id');

    // El correlation_id es un UUID: su primer bloque son 8 caracteres hexadecimales que a veces
    // salen todos dígitos y parecerían un DNI. Se quitan los UUID antes de buscar.
    $withoutUuids = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '', $contents);

    foreach (['/\b\d{8}\b/', '/\b9\d{8}\b/', '/[\w.+-]+@[\w-]+\.[\w.]+/', '/Siempre Viva/', '/secreto/'] as $pattern) {
        expect(preg_match($pattern, $withoutUuids))->toBe(0, "el log contiene {$pattern}");
    }
})->group('RNF-110');

it('returns the correlation id and the security headers on every response', function () {
    $incoming = '7d0c9e1a-2b7f-4f53-9a55-0d1b8e7f4a10';

    $response = $this->getJson('/api/v1/auth/me', ['X-Correlation-Id' => $incoming]);
    $response->assertUnauthorized()
        ->assertHeader('X-Correlation-Id', $incoming)
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");

    $generated = $this->postJson('/api/v1/auth/login', ['email' => 'x@y.test', 'password' => 'x'])->headers->get('X-Correlation-Id');
    expect($generated)->toMatch('/^[0-9a-f-]{36}$/')->not->toBe($incoming);
})->group('RNF-125', 'RNF-095');

it('sends CORS headers only to listed origins', function () {
    $listed = $this->call('OPTIONS', '/api/v1/patients', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);
    $unlisted = $this->call('OPTIONS', '/api/v1/patients', server: [
        'HTTP_ORIGIN' => 'https://malicioso.test',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ]);

    expect($listed->headers->get('Access-Control-Allow-Origin'))->toBe('http://localhost:5173')
        ->and($unlisted->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->group('RNF-096', 'DD-44');
