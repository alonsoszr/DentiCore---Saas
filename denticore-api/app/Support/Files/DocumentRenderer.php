<?php

namespace App\Support\Files;

/**
 * Genera el contenido de un tipo de documento (`generated_documents.kind`). Cada módulo
 * registra su renderizador en config/documents.php (p. ej. el presupuesto en MS-03).
 */
interface DocumentRenderer
{
    /**
     * HTML del documento; GenerateDocumentJob lo convierte a PDF.
     */
    public function html(GeneratedDocument $document): string;

    public function fileName(GeneratedDocument $document): string;
}
