<?php

namespace Tests\Support;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Services\ArcoRequestService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentService;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Solicitudes ARCO sintéticas para las pruebas de CUS-64 (RES-08).
 */
final class ArcoFixtures
{
    /**
     * Oficial de Datos Personales de la clínica, autenticado (CUS-64, precondición 1).
     */
    public static function officer(Tenant $tenant): User
    {
        return test()->actingAsRole('clinic_admin', $tenant, ['is_data_officer' => true]);
    }

    /**
     * Paciente adulto que otorgó, además de la atención, las finalidades opcionales de
     * notificaciones (b) y encuestas (e), que no dependen del plan (CA-17.5).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function patientWithOptionalPurposes(Tenant $tenant, array $attributes = []): Patient
    {
        $patient = ClinicalFixtures::patient($tenant, false, $attributes);

        TenantContext::run($tenant, fn () => app(ConsentService::class)->grant($patient, [
            'channel' => 'presencial',
            'confirmation_document_number' => $patient->document_number,
            'purpose_notifications' => true,
            'purpose_surveys' => true,
        ], User::factory()->for($tenant)->create(['role' => 'receptionist']), '127.0.0.1'));

        return $patient;
    }

    /**
     * Solicitud del titular registrada en la clínica con su documento (CUS-63), en estado `recibida`.
     */
    public static function request(Tenant $tenant, Patient $patient, string $type = 'acceso'): ArcoRequest
    {
        return TenantContext::run($tenant, fn () => app(ArcoRequestService::class)->registerAtClinic($patient, [
            'type' => $type,
            'requested_by' => 'titular',
            'description' => 'Solicitud sintética del titular para las pruebas.',
            'confirmation_document_number' => (string) $patient->document_number,
        ], User::factory()->for($tenant)->create(['role' => 'receptionist'])));
    }

    /**
     * Solicitud ya pasada a `en_tramite` por el oficial autenticado.
     */
    public static function started(Tenant $tenant, Patient $patient, string $type = 'acceso'): ArcoRequest
    {
        $request = self::request($tenant, $patient, $type);
        test()->postJson("/api/v1/arco-requests/{$request->uuid}/start")->assertOk();

        return $request;
    }

    /**
     * @return array{Idempotency-Key: string}
     */
    public static function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    public static function response(): string
    {
        return 'Atendimos su solicitud dentro del plazo según la Ley N° 29733.';
    }
}
