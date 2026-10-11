<?php

namespace App\Modules\Scheduling\Models;

use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\AppointmentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tipo de cita de la clínica (SDD §2.10 `appointment_types`; CUS-45; RF-145, RN-47): duración por
 * defecto de 10 a 240 min en múltiplos de 5, color y visibilidad en el portal.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name
 * @property int $default_duration_min
 * @property string $color
 * @property bool $portal_visible
 * @property bool $is_active
 */
#[UseFactory(AppointmentTypeFactory::class)]
class AppointmentType extends Model
{
    /** @use HasFactory<AppointmentTypeFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'default_duration_min' => 'integer',
            'portal_visible' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
