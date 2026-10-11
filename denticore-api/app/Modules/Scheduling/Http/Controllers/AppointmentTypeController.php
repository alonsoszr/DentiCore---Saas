<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Scheduling\Http\Requests\AppointmentTypeRequest;
use App\Modules\Scheduling\Http\Resources\AppointmentTypeResource;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Tipos de cita (SDD §4.3.8; CUS-45; RF-145). El personal los consulta; solo el Administrador de
 * Clínica los crea y edita (rol en la ruta). Otra clínica → 404 por el Global Scope.
 */
class AppointmentTypeController extends Controller
{
    public function __construct(private AppointmentTypeService $types) {}

    public function index(): AnonymousResourceCollection
    {
        return AppointmentTypeResource::collection(AppointmentType::query()->orderBy('name')->get());
    }

    #[ProblemResponse(422, 'Nombre repetido, duración fuera de 10–240 min o no múltiplo de 5, o color inválido (RF-145)')]
    public function store(AppointmentTypeRequest $request): JsonResponse
    {
        /** @var array{name: string, default_duration_min: int, color: string, portal_visible?: bool, is_active?: bool} $data */
        $data = $request->validated();

        return AppointmentTypeResource::make($this->types->create($data))->response()->setStatusCode(201);
    }

    #[ProblemResponse(422, 'Nombre repetido, duración fuera de 10–240 min o no múltiplo de 5, o color inválido (RF-145)')]
    public function update(AppointmentTypeRequest $request, AppointmentType $type): AppointmentTypeResource
    {
        /** @var array{name?: string, default_duration_min?: int, color?: string, portal_visible?: bool, is_active?: bool} $data */
        $data = $request->validated();

        return AppointmentTypeResource::make($this->types->update($type, $data));
    }
}
