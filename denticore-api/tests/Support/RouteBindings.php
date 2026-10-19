<?php

namespace Tests\Support;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Models\Attention;
use App\Modules\Odontogram\Models\OdontogramEntry;
use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Modules\Risk\Models\RiskAlert;
use App\Modules\Risk\Models\RiskPrediction;
use App\Modules\Risk\Models\RiskVariableSet;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentType;
use App\Modules\Scheduling\Models\ScheduleBlock;
use App\Modules\Treatment\Models\Budget;
use App\Modules\Treatment\Models\PlanItem;
use App\Modules\Treatment\Models\Procedure;
use App\Modules\Treatment\Models\TreatmentPlan;
use Closure;
use Illuminate\Support\Str;
use LogicException;

/**
 * Registros sintéticos de una clínica para cada parámetro de las rutas de la API (RES-08). Los usan
 * la matriz de autorización (T-019) y el recorrido del Súper Administrador (T-018).
 */
final class RouteBindings
{
    /**
     * @return array<string, Closure(): string>
     */
    public static function of(Tenant $tenant): array
    {
        return [
            '{tenant}' => fn () => $tenant->uuid,
            '{user}' => fn () => User::factory()->for($tenant)->create(['role' => 'dentist'])->uuid,
            '{patient}' => fn () => Patient::factory()->for($tenant)->create()->uuid,
            // Registros inexistentes: basta con un uuid; el rol se rechaza antes de buscarlos.
            '{representative}' => fn () => (string) Str::uuid(),
            '{consent}' => fn () => (string) Str::uuid(),
            '{template}' => fn () => (string) Str::uuid(),
            '{informedConsent}' => fn () => (string) Str::uuid(),
            '{diagnosis}' => fn () => (string) Str::uuid(),
            '{line}' => fn () => (string) Str::uuid(),
            '{document}' => fn () => (string) Str::uuid(),
            '{arcoRequest}' => fn () => (string) Str::uuid(),
            '{attention}' => fn () => Attention::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{tooth}' => fn () => '16',
            '{procedure}' => fn () => Procedure::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{plan}' => fn () => TreatmentPlan::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{item}' => fn () => PlanItem::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{entry}' => fn () => OdontogramEntry::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{budget}' => fn () => Budget::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{dentist}' => fn () => User::factory()->for($tenant)->create(['role' => 'dentist'])->uuid,
            '{block}' => fn () => ScheduleBlock::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{type}' => fn () => AppointmentType::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{appointment}' => fn () => Appointment::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{set}' => fn () => RiskVariableSet::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{prediction}' => fn () => RiskPrediction::factory()->create(['tenant_id' => $tenant->id])->uuid,
            '{alert}' => fn () => RiskAlert::factory()->create(['tenant_id' => $tenant->id])->uuid,
        ];
    }

    /**
     * Ruta concreta con registros de la clínica en cada parámetro.
     */
    public static function path(string $uri, Tenant $tenant): string
    {
        $bindings = self::of($tenant);

        return '/'.preg_replace_callback(
            '/\{[a-z_]+\}/i',
            fn (array $match): string => ($bindings[$match[0]] ?? throw new LogicException("Sin registro para {$match[0]} en {$uri}."))(),
            $uri,
        );
    }
}
