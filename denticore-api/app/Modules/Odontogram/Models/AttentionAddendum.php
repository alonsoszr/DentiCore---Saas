<?php

namespace App\Modules\Odontogram\Models;

use App\Modules\Identity\Models\User;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Adenda de una atención cerrada (SDD §2.6 `attention_addenda`; CUS-81, RF-097, RN-77, RN-78):
 * inmutable, con autor y su COP. Su motivo de consulta completa una atención `cerrada_incompleta`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $attention_id
 * @property string $text
 * @property string|null $chief_complaint
 * @property int $author_id
 * @property string $author_cop
 * @property Carbon $created_at
 * @property-read Attention $attention
 * @property-read User $author
 * @property-read Collection<int, AttentionDiagnosis> $diagnoses
 */
class AttentionAddendum extends Model
{
    use BelongsToTenant, HasUuid;

    protected $table = 'attention_addenda';

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return HasMany<AttentionDiagnosis, $this>
     */
    public function diagnoses(): HasMany
    {
        return $this->hasMany(AttentionDiagnosis::class, 'addendum_id');
    }
}
