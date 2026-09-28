<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Policies\PatientPolicy;
use App\Support\Audit\Auditable;
use App\Support\Database\HasUuid;
use App\Support\Encryption\TenantEncrypted;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ficha del paciente (SDD §2.5 `patients`; esquema heredado de las fases 0–3 que TASK-031
 * expande). document_id y phone se cifran en reposo
 * con la clave de la clínica (cast TenantEncrypted); tenant_id y user_id nunca son
 * asignables masivamente.
 *
 * @property Carbon $birth_date
 */
#[Fillable(['document_id', 'first_name', 'last_name', 'birth_date', 'phone', 'email', 'medical_history'])]
#[Hidden(['document_id_hash'])]
#[UseFactory(PatientFactory::class)]
#[UsePolicy(PatientPolicy::class)]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use Auditable, BelongsToTenant, HasFactory, HasUuid;

    /**
     * Estructura de medical_history: tres listas de texto y una observación libre.
     *
     * @var list<string>
     */
    public const MEDICAL_HISTORY_KEYS = ['alergias', 'enfermedades', 'medicamentos', 'observaciones'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_id' => TenantEncrypted::class.':document_id_hash',
            'phone' => TenantEncrypted::class,
            'birth_date' => 'date',
            'medical_history' => 'array',
        ];
    }

    public function auditPatientUuid(): ?string
    {
        return $this->uuid;
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Cuenta de portal del paciente (opcional).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
