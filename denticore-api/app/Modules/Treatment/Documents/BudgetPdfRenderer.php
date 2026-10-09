<?php

namespace App\Modules\Treatment\Documents;

use App\Modules\Treatment\Models\Budget;
use App\Support\Files\DocumentRenderer;
use App\Support\Files\GeneratedDocument;
use App\Support\Files\StoredFile;
use App\Support\Time\ClinicClock;
use Illuminate\Support\Facades\Storage;

/**
 * PDF del presupuesto emitido (RF-118; DD-18, RN-75): logo y datos de la clínica, número, fechas
 * de emisión y vencimiento en la zona de la clínica, paciente y número de HC, odontólogo con COP,
 * líneas, base, IGV, total y condiciones copiadas al emitir.
 */
class BudgetPdfRenderer implements DocumentRenderer
{
    public function html(GeneratedDocument $document): string
    {
        $budget = $this->budget($document);
        $clock = ClinicClock::for($budget->tenant);
        $date = fn ($instant) => $instant?->copy()->setTimezone($clock->timezone())->format('d/m/Y');

        return view('documents.budget', [
            'budget' => $budget,
            'clinic' => $budget->tenant,
            'logo' => $this->logo($budget->tenant->logo_file_id),
            'patient' => $budget->patient,
            'dentist' => $budget->dentist,
            'issuedOn' => $date($budget->issued_at),
            'expiresOn' => $date($budget->expires_at),
            'money' => fn (string $amount): string => self::money($amount),
        ])->render();
    }

    public function fileName(GeneratedDocument $document): string
    {
        return 'presupuesto-'.($this->budget($document)->number ?? $document->uuid).'.pdf';
    }

    /**
     * Importe con el formato de RNF-189 (`S/ 1,234.56`), sin pasar por float (DI-05).
     */
    public static function money(string $amount): string
    {
        [$units, $cents] = array_pad(explode('.', $amount), 2, '00');

        return 'S/ '.number_format((int) $units).'.'.str_pad(substr($cents, 0, 2), 2, '0');
    }

    private function budget(GeneratedDocument $document): Budget
    {
        return Budget::query()
            ->with(['tenant', 'patient', 'dentist', 'lines'])
            ->findOrFail($document->documentable_id);
    }

    /**
     * Logo de la clínica embebido como data URI (dompdf no lee del disco s3); null si no hay.
     */
    private function logo(?int $fileId): ?string
    {
        $file = $fileId === null ? null : StoredFile::query()->find($fileId);

        if ($file === null || ! $file->isDeliverable() || ! str_starts_with($file->mime_type, 'image/')) {
            return null;
        }

        $contents = Storage::disk($file->disk)->get($file->path);

        return $contents === null ? null : "data:{$file->mime_type};base64,".base64_encode($contents);
    }
}
