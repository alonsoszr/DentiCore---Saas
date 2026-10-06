<?php

namespace App\Modules\Odontogram\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Código CIE-10 (SDD §2.6 `cie10_codes`; DD-30, RF-085). Catálogo de plataforma: sin clínica.
 *
 * @property int $id
 * @property string $code
 * @property string $description
 * @property string $chapter
 * @property bool $is_dental
 * @property string $search_text
 * @property bool $is_active
 */
class Cie10Code extends Model
{
    protected $table = 'cie10_codes';

    protected function casts(): array
    {
        return [
            'is_dental' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Búsqueda por descripción (sin tildes ni mayúsculas) o por prefijo del código; el capítulo
     * odontológico K00–K14 va primero (DD-30).
     *
     * @return Builder<self>
     */
    public static function search(string $term, int $limit = 20): Builder
    {
        $normalized = Str::of(Str::ascii($term))->lower()->squish()->toString();
        $query = self::query()->where('is_active', true);

        if ($normalized === '') {
            return $query->whereRaw('false');
        }

        $like = '%'.addcslashes($normalized, '%_\\').'%';

        return $query
            ->where(fn (Builder $match) => $match
                ->where('search_text', 'ilike', $like)
                ->orWhere('code', 'ilike', addcslashes(strtoupper($normalized), '%_\\').'%'))
            ->orderByDesc('is_dental')
            ->orderBy('code')
            ->limit($limit);
    }
}
