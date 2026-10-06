<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;

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
}
