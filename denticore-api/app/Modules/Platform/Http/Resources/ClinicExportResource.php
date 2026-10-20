<?php

namespace App\Modules\Platform\Http\Resources;

use App\Support\Files\FileStorage;
use App\Support\Files\GeneratedDocument;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/** @mixin GeneratedDocument */
class ClinicExportResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        $file = $this->status === 'listo' ? $this->storedFile : null;

        return [
            /** @var 'exportacion_clinica' */
            'kind' => $this->kind,
            /** @var 'pendiente'|'generando'|'listo'|'fallido' */
            'status' => $this->status,
            'url' => $file === null ? null : app(FileStorage::class)->temporaryUrl($file, $request->user()),
        ];
    }
}
