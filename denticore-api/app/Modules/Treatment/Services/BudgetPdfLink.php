<?php

namespace App\Modules\Treatment\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Treatment\Models\Budget;
use App\Support\Files\FileStorage;

/**
 * PDF del presupuesto (CUS-35, CUS-36; RF-118, RF-121; DD-18): estado de la generación y, cuando
 * está listo, su URL firmada de 10 minutos. Lo usan el personal y el portal.
 */
class BudgetPdfLink
{
    public function __construct(private FileStorage $files) {}

    /**
     * @return array{status: 'pendiente'|'generando'|'listo'|'fallido'|null, url: string|null}
     */
    public function for(Budget $budget, ?User $viewer): array
    {
        $document = $budget->pdfDocument;
        $file = $document?->status === 'listo' ? $document->storedFile : null;

        return [
            'status' => $document?->status,
            'url' => $file === null ? null : $this->files->temporaryUrl($file, $viewer),
        ];
    }
}
