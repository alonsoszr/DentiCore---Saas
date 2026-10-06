<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Datos clínicos sintéticos compartidos por las pruebas de M04 (RES-08).
 */
final class ClinicalFixtures
{
    /**
     * Paciente adulto de la clínica, con consentimiento de datos vigente salvo que se indique.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function patient(Tenant $tenant, bool $withConsent = true, array $attributes = []): Patient
    {
        return TenantContext::run($tenant, function () use ($tenant, $withConsent, $attributes) {
            $patient = Patient::factory()->for($tenant)->create(['birth_date' => '1990-01-31', ...$attributes]);

            if ($withConsent) {
                app(ConsentService::class)->grant(
                    $patient,
                    ['channel' => 'presencial', 'confirmation_document_number' => $patient->document_number],
                    User::factory()->for($tenant)->create(['role' => 'receptionist']),
                    '127.0.0.1',
                );
            }

            return $patient;
        });
    }

    public static function attention(Tenant $tenant, string $uuid): Attention
    {
        return TenantContext::run($tenant, fn () => Attention::query()->where('uuid', $uuid)->firstOrFail());
    }

    /**
     * Hallazgo de superficie sintético del catálogo, con un estado rojo (`ACTIVA`) y uno azul
     * (`DETENIDA`).
     */
    public static function cariesFinding(): FindingCatalog
    {
        return FindingCatalog::factory()
            ->has(FindingState::factory()->sequence(
                ['code' => 'ACTIVA', 'name' => 'Activa', 'color' => 'rojo', 'acronym' => 'CA'],
                ['code' => 'DETENIDA', 'name' => 'Detenida', 'color' => 'azul', 'acronym' => 'CD'],
            )->count(2), 'states')
            ->create(['code' => 'PRUEBA_CARIES', 'name' => 'Caries de prueba', 'acronym' => null, 'level' => 'superficie']);
    }

    /**
     * Atención abierta por el odontólogo autenticado para un paciente nuevo; la primera del
     * paciente abre su odontograma inicial.
     */
    public static function openAttention(Tenant $tenant, bool $withConsent = true): Attention
    {
        $patient = self::patient($tenant, $withConsent);
        $response = test()->postJson("/api/v1/patients/{$patient->uuid}/attentions", [], ['Idempotency-Key' => (string) Str::uuid()]);

        return self::attention($tenant, $response->json('data.id'));
    }

    /**
     * Registra un hallazgo de `cariesFinding()` (pieza 36, oclusal y mesial) como el usuario autenticado.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function recordFinding(Attention $attention, array $payload = []): TestResponse
    {
        return test()->postJson(
            "/api/v1/attentions/{$attention->uuid}/odontogram-entries",
            ['tooth' => 36, 'surfaces' => ['O', 'M'], 'finding_code' => 'PRUEBA_CARIES', 'state_code' => 'ACTIVA', 'note' => 'Lesión cavitada', ...$payload],
            ['Idempotency-Key' => (string) Str::uuid()],
        );
    }
}
