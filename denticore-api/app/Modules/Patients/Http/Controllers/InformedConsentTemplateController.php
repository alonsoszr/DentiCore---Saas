<?php

namespace App\Modules\Patients\Http\Controllers;

use App\Modules\Patients\Http\Resources\InformedConsentTemplateResource;
use App\Modules\Patients\Models\InformedConsentTemplate;
use App\Modules\Patients\Services\InformedConsentTemplateService;
use App\Support\Http\Controller;
use App\Support\Http\ProblemResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Plantillas de consentimiento informado (SDD §4.3.3; CUS-82, RF-072). La plantilla se resuelve
 * con el Global Scope de la clínica (otra clínica → 404).
 */
class InformedConsentTemplateController extends Controller
{
    public function __construct(private InformedConsentTemplateService $templates) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', InformedConsentTemplate::class);

        return InformedConsentTemplateResource::collection(
            InformedConsentTemplate::query()
                ->with('currentVersion', 'procedures')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(),
        );
    }

    /**
     * RF-072: crea la versión 1 y asocia los procedimientos.
     */
    #[ProblemResponse(422, 'Un procedimiento no pertenece a la clínica')]
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', InformedConsentTemplate::class);

        /** @var array{title: string, body: string, procedures: list<string>} $data */
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string'],
            'procedures' => ['required', 'array'],
            'procedures.*' => ['required', 'uuid'],
        ]);

        $template = $this->templates->create($data, $request->user());

        return InformedConsentTemplateResource::make($template)->response()->setStatusCode(201);
    }

    /**
     * RF-072: la versión solo cambia cuando cambia el cuerpo; título y procedimientos se
     * actualizan en la misma plantilla.
     */
    #[ProblemResponse(422, 'Un procedimiento no pertenece a la clínica')]
    public function update(Request $request, InformedConsentTemplate $template): JsonResponse
    {
        Gate::authorize('update', $template);

        /** @var array{title?: string, body?: string, procedures?: list<string>} $data */
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:150'],
            'body' => ['sometimes', 'string'],
            'procedures' => ['sometimes', 'array'],
            'procedures.*' => ['required', 'uuid'],
        ]);

        $template = $this->templates->update($template, $data, $request->user());

        return InformedConsentTemplateResource::make($template)->response()->setStatusCode(200);
    }

    /**
     * RF-072: al desactivar, los consentimientos ya firmados no se alteran (DD-31).
     */
    public function deactivate(InformedConsentTemplate $template): JsonResponse
    {
        Gate::authorize('manage', $template);

        $template = $this->templates->deactivate($template);

        return InformedConsentTemplateResource::make($template)->response()->setStatusCode(200);
    }
}
