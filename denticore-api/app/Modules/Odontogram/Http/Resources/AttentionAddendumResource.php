<?php

namespace App\Modules\Odontogram\Http\Resources;

use App\Modules\Odontogram\Models\AttentionAddendum;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Adenda de una atención cerrada (CUS-81; RF-097): texto, motivo, autor con su COP, fecha y los
 * diagnósticos que agrega. `attention_status` informa si la adenda completó la atención (RN-77).
 *
 * @mixin AttentionAddendum
 */
class AttentionAddendumResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'text' => $this->text,
            'chief_complaint' => $this->chief_complaint,
            'author' => [
                'id' => $this->author->uuid,
                'name' => $this->author->name,
                'cop' => $this->author_cop,
            ],
            'created_at' => $this->created_at,
            'diagnoses' => AttentionDiagnosisResource::collection($this->whenLoaded('diagnoses')),
            /** @var 'cerrada'|'cerrada_incompleta' */
            'attention_status' => $this->whenLoaded('attention', fn () => $this->attention->status),
        ];
    }
}
