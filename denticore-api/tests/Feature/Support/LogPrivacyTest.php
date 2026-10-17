<?php

/*
 * Registros sin datos personales (TASK-092; SDD §1.7; RNF-110). T-164 sobre una corrida sintética
 * completa de E1: alta de paciente, representante, consentimiento, atención con hallazgo,
 * presupuesto decidido en el portal, citas, un inicio de sesión fallido y errores que traen datos
 * personales en su mensaje.
 */

use App\Support\Http\RedactPersonalData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Handler\NullHandler;
use Tests\Support\PortalFixtures;

/** Canal de prueba con el redactor, como default de la aplicación; devuelve la ruta del archivo. */
function logPrivacyProbe(): string
{
    $file = storage_path('logs/privacidad-completa-'.uniqid().'.log');
    config(['logging.channels.privacy_full' => ['driver' => 'single', 'path' => $file, 'tap' => [RedactPersonalData::class]]]);
    config(['logging.default' => 'privacy_full']);
    Log::forgetChannel('privacy_full');

    return $file;
}

it('finds no personal data patterns in logs of a full synthetic run', function () {
    Storage::fake('s3');
    Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'America/Lima'));
    $file = logPrivacyProbe();

    // Recorrido de E1 con datos sintéticos conocidos (RES-08).
    $clinic = PortalFixtures::bookingClinic();
    $tenant = $clinic['tenant'];
    $this->actingAsRole('receptionist', $tenant);
    $this->postJson('/api/v1/patients', [
        'document_type' => 'dni', 'document_number' => '45678912', 'first_name' => 'Rosa', 'last_name' => 'Quispe',
        'birth_date' => '1990-05-10', 'sex' => 'femenino', 'phone' => '987654321', 'email' => 'rosa@correo.test',
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    // El mismo documento otra vez: el error de unicidad trae el documento en su detalle.
    $this->postJson('/api/v1/patients', [
        'document_type' => 'dni', 'document_number' => '45678912', 'first_name' => 'Rosa', 'last_name' => 'Quispe',
        'birth_date' => '1990-05-10', 'sex' => 'femenino', 'phone' => '987654321',
    ], ['Idempotency-Key' => (string) Str::uuid()]);
    $minor = PortalFixtures::minor($tenant);
    PortalFixtures::representative($tenant, $minor);
    PortalFixtures::recordedFinding($tenant, $clinic['patient']);
    $budget = PortalFixtures::issuedBudget($tenant, $clinic['patient']);
    $this->actingWithToken($clinic['user'])
        ->postJson("/api/v1/portal/budgets/{$budget}/decision", ['decision' => 'aceptado'], ['Idempotency-Key' => (string) Str::uuid()])
        ->assertOk();
    $this->postJson("/api/v1/portal/patients/{$clinic['patient']->uuid}/appointments", [
        'appointment_type_id' => $clinic['type']->uuid,
        'dentist_id' => $clinic['dentist']->uuid,
        'starts_at' => Carbon::parse('2026-11-03 15:00', 'America/Lima')->toIso8601String(),
    ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['tenant_slug' => $tenant->slug, 'email' => 'rosa@correo.test', 'password' => 'mala-clave'])
        ->assertUnauthorized();

    // Errores que por descuido traen datos personales en su mensaje o en una excepción.
    Log::warning('Paciente 45678912 con celular 987654321 y correo rosa@correo.test', ['detalle' => 'DNI 45678912']);
    Log::error('Falló el registro', ['exception' => new QueryException(
        'pgsql', 'insert into patients (document_number, phone) values (?, ?)', ['45678912', '987654321'], new RuntimeException('duplicate key'),
    )]);
    report(new RuntimeException('No se pudo notificar a rosa@correo.test (45678912)'));

    $contents = (string) file_get_contents($file);
    @unlink($file);

    expect($contents)->toContain('Falló el registro')->toContain('[documento]');

    // Los UUID (correlation_id y recursos) pueden empezar con 8 dígitos: se quitan antes de buscar.
    $withoutUuids = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '', $contents);
    foreach (['/(?<![\w-])\d{8}(?![\w-])/', '/(?<![\w-])9\d{8}(?![\w-])/', '/[\w.+-]+@[\w-]+\.[\w.]+/', '/Quispe/', '/Rosa/'] as $pattern) {
        expect(preg_match($pattern, $withoutUuids))->toBe(0, "el log contiene {$pattern}");
    }
})->group('T-164', 'RNF-110');

it('applies the redactor to every logging channel that writes records', function () {
    // `stack` delega en sus canales y `null` descarta; `emergency` es el respaldo interno que
    // Laravel arma sin `tap` solo cuando falla el canal configurado.
    $channels = collect(config('logging.channels'))
        ->except('emergency')
        ->reject(fn (array $channel) => in_array($channel['driver'] ?? null, ['stack'], true) || ($channel['handler'] ?? null) === NullHandler::class);

    expect($channels->filter(fn (array $channel) => ! in_array(RedactPersonalData::class, $channel['tap'] ?? [], true))->keys()->all())->toBe([]);
})->group('T-164', 'RNF-110');
