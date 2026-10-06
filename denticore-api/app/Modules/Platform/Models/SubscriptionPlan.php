<?php

namespace App\Modules\Platform\Models;

use App\Support\Database\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plan de suscripción (SDD §2.3 `subscription_plans`, DD-16, RN-08). Tabla de plataforma
 * sembrada por su migración; no se crea desde la API.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property string $name
 * @property int|null $max_dentists
 * @property bool $includes_ai
 * @property bool $includes_risk
 * @property bool $includes_analytics
 * @property int|null $ai_monthly_quota
 * @property int $rate_limit_per_minute
 * @property string|null $monthly_price_pen
 */
class SubscriptionPlan extends Model
{
    use HasUuid;

    protected function casts(): array
    {
        return [
            'max_dentists' => 'integer',
            'includes_ai' => 'boolean',
            'includes_risk' => 'boolean',
            'includes_analytics' => 'boolean',
            'ai_monthly_quota' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'monthly_price_pen' => 'decimal:2',
        ];
    }

    public static function forCode(string $code): self
    {
        return static::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
