<?php

namespace App\Modules\Risk\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Risk\Http\Requests\AcknowledgeRiskAlertRequest;
use App\Modules\Risk\Http\Resources\RiskAlertResource;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Services\RiskAlertService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Alertas de riesgo alto del odontólogo (SDD §4.3.9; CUS-58; RN-65, RF-171). Cada odontólogo ve y
 * reconoce solo las suyas; una alerta ajena responde 404. El orden y el conteo de RF-172 llegan en
 * MS-15: aquí van primero las abiertas y después las más recientes.
 */
class RiskAlertController extends Controller
{
    public function __construct(private RiskAlertService $alerts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        assert($user instanceof User);

        $alerts = RiskAlert::query()
            ->where('dentist_id', $user->id)
            ->with(['patient', 'prediction', 'treatmentPlan', 'appointment'])
            ->orderByRaw("status = 'abierta' desc")
            ->latest('id')
            ->limit(100)
            ->get();

        return RiskAlertResource::collection($alerts);
    }

    #[ProblemResponse(409, 'La alerta ya fue reconocida (RN-65)')]
    #[ProblemResponse(422, 'Falta la acción, la justificación tiene menos de 20 caracteres o el plan o la cita no son del paciente (RN-65, RF-171)')]
    public function acknowledge(AcknowledgeRiskAlertRequest $request, RiskAlert $alert): RiskAlertResource
    {
        /** @var array{action: string, treatment_plan_id?: string|null, appointment_id?: string|null, justification?: string|null} $data */
        $data = $request->validated();

        return RiskAlertResource::make($this->alerts->acknowledge($alert, $data)->load(['patient', 'prediction', 'treatmentPlan', 'appointment']));
    }
}
