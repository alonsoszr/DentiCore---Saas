<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Http\Requests\RevokeConsentPurposesRequest;
use App\Modules\Patients\Http\Resources\ConsentResource;
use App\Modules\Patients\Models\Consent;
use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\ConsentRevocationService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;

/**
 * Revocación de finalidades desde la clínica (SDD §4.3.3; CUS-18, RF-068, RF-069): canal
 * `presencial`, registrada por recepción o el administrador.
 */
class ConsentRevocationController extends Controller
{
    public function __construct(private ConsentRevocationService $revocations) {}

    #[ProblemResponse(409, 'El consentimiento ya no está vigente (RN-14)')]
    public function store(RevokeConsentPurposesRequest $request, Patient $patient, Consent $consent): ConsentResource
    {
        /** @var array{purposes: list<string>, reason?: string|null} $data */
        $data = $request->validated();
        /** @var User $user */
        $user = $request->user();

        return ConsentResource::make($this->revocations->revoke(
            $patient, $consent, $data['purposes'], $data['reason'] ?? null, $user, 'presencial',
        ));
    }
}
