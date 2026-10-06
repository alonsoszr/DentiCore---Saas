<?php

namespace App\Modules\Odontogram\Http\Resources;

use App\Modules\Odontogram\Models\FindingNoTreatDecision;
use App\Support\Http\ApiResource;
use Illuminate\Http\Request;

/**
 * Decisión de no tratar un hallazgo (CUS-34; RF-113, RN-27): la entrada del odontograma, el
 * motivo, quién decidió y cuándo.
 *
 * @mixin FindingNoTreatDecision
 */
class FindingNoTreatDecisionResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    protected function fields(Request $request): array
    {
        return [
            'finding_id' => $this->odontogram_entry_uuid,
            'reason' => $this->reason,
            'decided_by' => [
                'id' => $this->decider->uuid,
                'name' => $this->decider->name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
