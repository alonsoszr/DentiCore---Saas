<?php

namespace App\Modules\Platform\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Parámetros de una clínica (SDD §2.3 `clinic_settings`, 1:1 con `tenants`; RF-024 a RF-027).
 * Los límites son CHECK de la tabla; la fila nace con la clínica y sus valores por defecto.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property bool $prices_include_igv
 * @property string $discount_cap_pct
 * @property int $budget_validity_days
 * @property int $portal_cancel_hours
 * @property bool $self_booking_enabled
 * @property bool $ai_enabled
 * @property Carbon|null $ai_enabled_at
 * @property int|null $ai_enabled_by
 * @property string|null $budget_terms
 * @property string|null $complaints_book_url
 */
#[Fillable([
    'prices_include_igv', 'discount_cap_pct', 'budget_validity_days', 'portal_cancel_hours',
    'self_booking_enabled', 'budget_terms',
])]
class ClinicSetting extends Model
{
    use BelongsToTenant, HasUuid;

    protected function casts(): array
    {
        return [
            'prices_include_igv' => 'boolean',
            'discount_cap_pct' => 'decimal:2',
            'budget_validity_days' => 'integer',
            'portal_cancel_hours' => 'integer',
            'self_booking_enabled' => 'boolean',
            'ai_enabled' => 'boolean',
            'ai_enabled_at' => 'datetime',
        ];
    }
}
