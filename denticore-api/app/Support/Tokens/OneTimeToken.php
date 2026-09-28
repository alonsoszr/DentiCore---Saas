<?php

namespace App\Support\Tokens;

use App\Support\Database\HasUuid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Token de un solo uso (SDD §2.4 `one_time_tokens`, DI-15). Solo se guarda el SHA-256 del
 * token. tenant_id nulable: excepción de BelongsToTenant (SDD §1.6.3); se resuelve siempre
 * por el hash del token.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $tenant_id
 * @property TokenPurpose $purpose
 * @property string $token_hash
 * @property string $tokenable_type
 * @property int $tokenable_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property CarbonImmutable|null $invalidated_at
 * @property int $failed_attempts
 */
#[Fillable(['tenant_id', 'purpose', 'token_hash', 'tokenable_type', 'tokenable_id', 'expires_at'])]
#[Hidden(['token_hash'])]
class OneTimeToken extends Model
{
    use HasUuid;

    public const MAX_FAILED_ATTEMPTS = 5;

    /**
     * @return MorphTo<Model, $this>
     */
    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->invalidated_at === null
            && $this->failed_attempts < self::MAX_FAILED_ATTEMPTS
            && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => TokenPurpose::class,
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
            'failed_attempts' => 'integer',
        ];
    }
}
