<?php

use App\Modules\Patients\Documents\ConsentCertificateRenderer;
use App\Modules\Treatment\Documents\BudgetPdfRenderer;

return [

    /*
    |--------------------------------------------------------------------------
    | Renderizadores de documentos generados (SDD DD-18, DI-16)
    |--------------------------------------------------------------------------
    |
    | Tipo (`generated_documents.kind`) => clase que implementa
    | App\Support\Files\DocumentRenderer. Cada módulo registra los suyos.
    |
    */

    'renderers' => [
        // M03: constancia del consentimiento de datos (RF-066).
        'constancia_consentimiento' => ConsentCertificateRenderer::class,
        // M05: presupuesto emitido (RF-118).
        'presupuesto' => BudgetPdfRenderer::class,
    ],

];
