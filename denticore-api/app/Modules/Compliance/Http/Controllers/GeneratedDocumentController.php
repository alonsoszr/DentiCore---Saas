<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Support\Files\FileStorage;
use App\Support\Files\GeneratedDocument;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Documento generado (SDD §4.3, DI-16; CUS-36, CUS-62; RF-121, RF-180): estado de la generación y,
 * cuando está listo, su URL firmada de 10 minutos. Lo autoriza la Policy `view` del registro al
 * que pertenece (presupuesto, constancia de consentimiento…).
 */
class GeneratedDocumentController extends Controller
{
    public function __construct(private FileStorage $files) {}

    public function show(Request $request, GeneratedDocument $document): JsonResponse
    {
        $documentable = $document->documentable;
        abort_if($documentable === null, 404);
        Gate::authorize('view', $documentable);

        $file = $document->status === 'listo' ? $document->storedFile : null;

        return response()->json(['data' => [
            'id' => $document->uuid,
            'kind' => $document->kind,
            /** @var 'pendiente'|'generando'|'listo'|'fallido' */
            'status' => $document->status,
            'url' => $file === null ? null : $this->files->temporaryUrl($file, $request->user()),
        ]]);
    }
}
