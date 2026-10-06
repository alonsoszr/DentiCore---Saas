<?php

namespace App\Modules\Odontogram\Http\Controllers;

use App\Modules\Odontogram\Models\Cie10Code;
use App\Support\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Búsqueda en el catálogo CIE-10 (SDD §4.3; CUS-80; RF-085, DD-30): por código o texto, sin
 * tildes, con el capítulo odontológico K00–K14 primero. Catálogo de plataforma.
 */
class Cie10Controller extends Controller
{
    public function search(Request $request): JsonResponse
    {
        /** @var array{q: string} $data */
        $data = $request->validate(['q' => ['required', 'string', 'max:100']], [], ['q' => 'búsqueda']);

        return response()->json([
            'data' => Cie10Code::search($data['q'])->get()->map(fn (Cie10Code $code) => [
                'code' => $code->code,
                'description' => $code->description,
                'is_dental' => $code->is_dental,
            ])->all(),
        ]);
    }
}
