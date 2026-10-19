<?php

namespace App\Modules\Compliance\Services;

use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\LegalRepresentative;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentRevocationService;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Cancelación ARCO aceptada (SDD §5.11, DI-21; CUS-64 FA-1; RF-184, RN-68, RN-69). En una sola
 * transacción:
 *
 * - el paciente pasa a `bloqueado`: la HC permanece (CA-64.2) y `consent:*` rechaza todo registro
 *   clínico, predicción y notificación (CA-64.3);
 * - se eliminan los datos tratados solo para la finalidad opcional (d), con `app.arco_erasure`:
 *   `risk_variable_sets.sociodemographic` pasa a `{}` y el `value` de esas variables en
 *   `risk_predictions.explanation_individual`, a `null`;
 * - se revocan las finalidades opcionales otorgadas con el canal `arco`;
 * - se desactiva la cuenta de portal del titular y se cierran sus sesiones.
 *
 * Las respuestas de `satisfaction_surveys` se borrarán cuando exista M12 (E4).
 */
class ArcoCancellationService
{
    /** Variables de `risk_variable_sets.sociodemographic` que aparecen en la explicación (SDD §2.14). */
    public const SOCIODEMOGRAPHIC_FEATURES = ['education_level', 'employment_status', 'family_structure'];

    public function __construct(
        private ConsentRevocationService $revocations,
        private AuditLogger $audit,
    ) {}

    /**
     * @throws BusinessRuleException
     */
    public function apply(ArcoRequest $request, User $officer): ArcoRequest
    {
        if ($request->type !== 'cancelacion') {
            throw new BusinessRuleException('RF-184', 'Solo una solicitud de cancelación bloquea al paciente.');
        }

        return DB::transaction(function () use ($request, $officer): ArcoRequest {
            /** @var ArcoRequest $locked */
            $locked = ArcoRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            /** @var Patient $patient */
            $patient = Patient::query()->whereKey($locked->patient_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'en_tramite' || $patient->archive_status === 'bloqueado') {
                throw new BusinessRuleException('RF-184', 'La cancelación solo se aplica una vez, con la solicitud en trámite.', status: 409);
            }

            $patient->forceFill(['archive_status' => 'bloqueado'])->save();
            $this->audit->record(AuditEvent::PatientBlocked, $patient, ['archive_status'], actor: $officer);

            $this->eraseSociodemographicData($patient);
            $this->revokeOptionalPurposes($patient, $locked, $officer);
            $this->deactivatePortal($patient);

            return $locked;
        });
    }

    /**
     * DI-21: la excepción vale solo dentro de esta transacción y solo para estas columnas.
     */
    private function eraseSociodemographicData(Patient $patient): void
    {
        DB::select("SELECT set_config('app.arco_erasure', 'on', true)");

        DB::table('risk_variable_sets')
            ->where('patient_id', $patient->id)
            ->whereRaw("sociodemographic <> '{}'::jsonb")
            ->update(['sociodemographic' => '{}', 'updated_at' => now()]);

        DB::table('risk_predictions')
            ->where('patient_id', $patient->id)
            ->orderBy('id')
            ->get(['id', 'explanation_individual'])
            ->each(function (object $prediction): void {
                /** @var list<array{feature: string, value: string|null, shap: float}> $items */
                $items = json_decode((string) $prediction->explanation_individual, true);
                $erased = array_map(
                    fn (array $item) => in_array($item['feature'], self::SOCIODEMOGRAPHIC_FEATURES, true) ? [...$item, 'value' => null] : $item,
                    $items,
                );

                if ($erased !== $items) {
                    DB::table('risk_predictions')->where('id', $prediction->id)->update(['explanation_individual' => json_encode($erased)]);
                }
            });

        DB::select("SELECT set_config('app.arco_erasure', 'off', true)");
    }

    /**
     * Revoca, con el canal `arco`, las finalidades opcionales otorgadas y aún vigentes (RN-14).
     */
    private function revokeOptionalPurposes(Patient $patient, ArcoRequest $request, User $officer): void
    {
        /** @var Consent|null $consent */
        $consent = Consent::query()->where('patient_id', $patient->id)->where('status', 'vigente')->first();

        if ($consent === null) {
            return;
        }

        $revoked = $consent->purposeRevocations()->pluck('purpose')->all();
        $purposes = array_values(array_filter(
            ArcoRequestService::OPTIONAL_PURPOSES,
            fn (string $purpose) => $consent->getAttribute(Consent::PURPOSES[$purpose]) === true && ! in_array($purpose, $revoked, true),
        ));

        if ($purposes !== []) {
            $this->revocations->revoke($patient, $consent, $purposes, null, $officer, 'arco', $request->id);
        }
    }

    /**
     * FA-1: la cuenta del titular queda inactiva y sin sesiones, salvo que represente a otros
     * pacientes vigentes (el portal ya no le muestra esta ficha bloqueada).
     */
    private function deactivatePortal(Patient $patient): void
    {
        $account = $patient->user;

        if ($account === null) {
            return;
        }

        $representsOthers = LegalRepresentative::query()
            ->where('user_id', $account->id)
            ->whereNull('valid_until')
            ->exists();

        if (! $representsOthers) {
            $account->forceFill(['status' => 'inactivo'])->save();
            $account->tokens()->delete();
        }
    }
}
