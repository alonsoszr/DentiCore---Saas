<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Models\FindingCatalog;
use App\Modules\Odontogram\Models\FindingState;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de hallazgos de la NTS N° 188 (SDD §4.3; CUS-22; RF-077, RF-078): solo los hallazgos
 * y estados vigentes, en el orden del catálogo. La SPA filtra por nivel y dentición.
 */
class FindingCatalogController extends Controller
{
    public function index(): JsonResponse
    {
        $findings = FindingCatalog::query()
            ->where('is_active', true)
            ->with(['states' => fn ($query) => $query->where('is_active', true)->orderBy('id')])
            ->orderBy('display_order')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $findings->map(fn (FindingCatalog $finding) => [
                'code' => $finding->code,
                'name' => $finding->name,
                'acronym' => $finding->acronym,
                /** @var 'pieza'|'superficie'|'tramo' */
                'level' => $finding->level,
                /** @var 'permanente'|'temporal'|'ambas' */
                'dentition' => $finding->dentition,
                'states' => $finding->states->map(fn (FindingState $state) => [
                    'code' => $state->code,
                    'name' => $state->name,
                    /** @var 'azul'|'rojo' */
                    'color' => $state->color,
                    'acronym' => $state->acronym,
                ])->all(),
            ])->all(),
        ]);
    }
}
