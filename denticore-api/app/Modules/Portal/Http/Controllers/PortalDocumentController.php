<?php

namespace App\Modules\Portal\Http\Controllers;

use App\Modules\Compliance\Services\ClinicalRecordCopyService;
use App\Modules\Identity\Models\User;
use App\Modules\Patients\Models\Patient;
use App\Modules\Portal\Services\PortalSubjectResolver;
use App\Support\Files\FileStorage;
use App\Support\Files\GeneratedDocument;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documentos del titular en el portal (SDD §4.3.10; CUS-62, RF-180, RN-70): la copia de su
 * historia clínica o la de su representado, en cualquier momento. `portal.subject` da 404 para
 * otro paciente; como el documento es polimórfico y ese middleware no lo resuelve, `show` hace la
 * misma comprobación aquí: una copia de uno de los sujetos de la cuenta o 404 (RN-03).
 */
class PortalDocumentController extends Controller
{
    /**
     * Tipos que el portal entrega por esta ruta; la portabilidad (RF-181) llega en MS-13.
     *
     * @var list<string>
     */
    private const KINDS = [ClinicalRecordCopyService::KIND];

    public function __construct(
        private ClinicalRecordCopyService $copies,
        private FileStorage $files,
        private PortalSubjectResolver $subjects,
    ) {}

    public function clinicalRecordCopy(Request $request, Patient $patient): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->document($this->copies->request($patient, $user), null)->setStatusCode(201);
    }

    public function show(Request $request, GeneratedDocument $document): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            in_array($document->kind, self::KINDS, true)
            && $document->documentable_type === (new Patient)->getMorphClass()
            && $this->subjects->for($user)->contains('id', $document->documentable_id),
            404,
        );

        $file = $document->status === 'listo' ? $document->storedFile : null;

        return $this->document($document, $file === null ? null : $this->files->temporaryUrl($file, $user));
    }

    private function document(GeneratedDocument $document, ?string $url): JsonResponse
    {
        return response()->json(['data' => [
            'id' => $document->uuid,
            'kind' => $document->kind,
            /** @var 'pendiente'|'generando'|'listo'|'fallido' */
            'status' => $document->status,
            'url' => $url,
        ]]);
    }
}
