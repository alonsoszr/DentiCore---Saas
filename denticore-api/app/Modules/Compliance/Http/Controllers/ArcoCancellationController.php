<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Modules\Compliance\Http\Resources\ArcoRequestResource;
use App\Modules\Compliance\Models\ArcoRequest;
use App\Modules\Compliance\Services\ArcoCancellationService;
use App\Modules\Identity\Models\User;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\Request;

/**
 * Cancelación ARCO aceptada (SDD §4.3.11, §5.11; CUS-64 FA-1; RF-184): bloquea al paciente y
 * elimina los datos de finalidades opcionales. La respuesta al titular se da después, al resolver.
 */
class ArcoCancellationController extends Controller
{
    public function __construct(private ArcoCancellationService $cancellations) {}

    #[ProblemResponse(409, 'La cancelación ya se aplicó o la solicitud no está en trámite (RF-184)')]
    #[ProblemResponse(422, 'La solicitud no es de cancelación (RF-184)')]
    public function store(Request $request, ArcoRequest $arcoRequest): ArcoRequestResource
    {
        /** @var User $officer */
        $officer = $request->user();

        return ArcoRequestResource::make($this->cancellations->apply($arcoRequest, $officer)->load('patient'));
    }
}
