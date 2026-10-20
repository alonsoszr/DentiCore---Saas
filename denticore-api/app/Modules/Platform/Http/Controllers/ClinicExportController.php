<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Platform\Http\Resources\ClinicExportResource;
use App\Modules\Platform\Services\ClinicExportService;
use App\Support\Files\GeneratedDocument;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** CUS-05: exportación de la clínica, también durante 90 días tras su cancelación (RF-020). */
class ClinicExportController extends Controller
{
    public function __construct(private ClinicExportService $exports) {}

    /** Registra la solicitud pendiente; TASK-139 la encolará en heavy y generará el ZIP. */
    #[ProblemResponse(403, 'Solo el administrador puede exportar dentro del plazo de la clínica (RN-07, RN-72)')]
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ClinicExportResource::make($this->exports->request($user))->response()->setStatusCode(201);
    }

    /** Consulta el estado y la URL firmada del documento; otra clínica o tipo de documento → 404. */
    #[ProblemResponse(403, 'El plazo de exportación de la clínica terminó (RN-07)')]
    public function show(Request $request, GeneratedDocument $document): ClinicExportResource
    {
        /** @var User $user */
        $user = $request->user();

        return ClinicExportResource::make($this->exports->view($document, $user));
    }
}
