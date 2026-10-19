<?php

namespace App\Modules\Patients\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\ConsentPurposeRevocation;
use App\Modules\Patients\Models\Patient;
use App\Support\Audit\AuditEvent;
use App\Support\Audit\AuditLogger;
use App\Support\Http\BusinessRuleException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Revocar finalidades del consentimiento vigente (SDD §5.10; CUS-18; RN-14, RF-068, RF-069). Cada
 * uso de una finalidad consulta ConsentGate en el momento, así que la revocación tiene efecto
 * inmediato. Revocar (a) `atencion` pasa el consentimiento a `revocado`: sin consentimiento vigente,
 * el registro clínico responde 422 (RN-10) y los datos existentes se conservan (RN-68).
 */
class ConsentRevocationService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  list<string>  $purposes  Claves de Consent::PURPOSES.
     * @param  'presencial'|'portal'|'arco'  $channel
     * @param  int|null  $arcoRequestId  Solicitud ARCO de oposición o cancelación que la origina (CUS-64).
     *
     * @throws BusinessRuleException|ValidationException|ModelNotFoundException<Consent>
     */
    public function revoke(Patient $patient, Consent $consent, array $purposes, ?string $reason, User $actor, string $channel, ?int $arcoRequestId = null): Consent
    {
        if ($consent->patient_id !== $patient->id) {
            throw (new ModelNotFoundException)->setModel(Consent::class, [$consent->uuid]);
        }

        return DB::transaction(function () use ($consent, $purposes, $reason, $actor, $channel, $arcoRequestId): Consent {
            /** @var Consent $locked */
            $locked = Consent::query()->whereKey($consent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'vigente') {
                throw new BusinessRuleException('RN-14', 'Solo se revocan finalidades del consentimiento vigente.', status: 409);
            }

            $this->ensureRevocable($locked, $purposes);

            foreach ($purposes as $purpose) {
                (new ConsentPurposeRevocation)->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'consent_id' => $locked->id,
                    'purpose' => $purpose,
                    'channel' => $channel,
                    'revoked_by_user_id' => $actor->id,
                    'reason' => $reason,
                    'arco_request_id' => $arcoRequestId,
                ])->save();
            }

            if (in_array('atencion', $purposes, true)) {
                $locked->forceFill(['status' => 'revocado', 'revoked_at' => now()])->save();
            }

            $sorted = $purposes;
            sort($sorted);
            $this->audit->record(AuditEvent::ConsentPurposeRevoked, $locked, meta: ['purposes' => implode(',', $sorted)], actor: $actor);

            return $locked->load('purposeRevocations');
        });
    }

    /**
     * Solo finalidades otorgadas en el consentimiento y aún no revocadas (422).
     *
     * @param  list<string>  $purposes
     *
     * @throws ValidationException
     */
    private function ensureRevocable(Consent $consent, array $purposes): void
    {
        $revoked = $consent->purposeRevocations()->pluck('purpose')->all();

        foreach ($purposes as $purpose) {
            if ($consent->getAttribute(Consent::PURPOSES[$purpose]) !== true) {
                throw ValidationException::withMessages(['purposes' => ["La finalidad «{$purpose}» no está otorgada en el consentimiento."]]);
            }

            if (in_array($purpose, $revoked, true)) {
                throw ValidationException::withMessages(['purposes' => ["La finalidad «{$purpose}» ya fue revocada."]]);
            }
        }
    }
}
