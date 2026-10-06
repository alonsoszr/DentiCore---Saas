<?php

namespace App\Modules\Patients\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Patients\Policies\PatientPolicy;
use App\Modules\Patients\Services\PatientIdentity;
use App\Support\Audit\Auditable;
use App\Support\Database\HasUuid;
use App\Support\Encryption\TenantEncrypted;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Time\ClinicClock;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Ficha del paciente (SDD §2.5 `patients`; esquema heredado de las fases 0–3 que TASK-031
 * expande). document_id y phone se cifran en reposo
 * con la clave de la clínica (cast TenantEncrypted); tenant_id y user_id nunca son
 * asignables masivamente.
 *
 * TASK-031 agregó la identificación de SDD §2.5 (tipo y número de documento, HC, sexo, dirección,
 * estado de archivo); mientras el registro heredado envíe `document_id`, la ficha completa las
 * columnas nuevas desde él (DNI). `document_id` se elimina en TASK-038.
 *
 * @property Carbon $birth_date
 * @property string|null $document_type
 * @property string|null $document_number
 * @property string|null $document_hash
 * @property string|null $clinical_record_number
 * @property string|null $clinical_record_hash
 * @property string|null $sex
 * @property string|null $address
 * @property string|null $search_name
 * @property string $archive_status
 * @property Carbon|null $first_attention_at
 * @property Carbon|null $last_attention_at
 * @property Carbon|null $deceased_on
 * @property int|null $created_by
 * @property array{alergias: list<string>, enfermedades: list<string>, medicamentos: list<string>, observaciones: string|null}|null $medical_history
 */
#[Fillable(['document_id', 'first_name', 'last_name', 'birth_date', 'phone', 'email', 'medical_history', 'sex', 'address'])]
#[Hidden(['document_id_hash', 'document_hash', 'clinical_record_hash'])]
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
            'document_number' => TenantEncrypted::class,
            'clinical_record_number' => TenantEncrypted::class,
            'phone' => TenantEncrypted::class,
            'address' => TenantEncrypted::class,
            'birth_date' => 'date',
            'medical_history' => 'array',
            'first_attention_at' => 'datetime',
            'last_attention_at' => 'datetime',
            'deceased_on' => 'date',
        ];
    }

    /**
     * Defaults de columna espejados en PHP (ver Tenant::$attributes).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'archive_status' => 'activo',
    ];

    protected static function booted(): void
    {
        static::saving(function (Patient $patient): void {
            // DI-14: nombre normalizado para la búsqueda por trigramas.
            if ($patient->isDirty(['first_name', 'last_name']) || $patient->search_name === null) {
                $patient->search_name = PatientIdentity::searchName((string) $patient->first_name, (string) $patient->last_name);
            }

            // Escritura doble de la etapa de expansión (Plan §1.5): el registro heredado solo
            // envía `document_id` (un DNI). Se retira en TASK-038.
            if ($patient->getAttributes()['document_number'] ?? null) {
                return;
            }

            $tenantId = $patient->tenant_id ?? TenantContext::idOrFail();
            $number = PatientIdentity::normalizedNumber($patient->document_id);
            $record = PatientIdentity::clinicalRecordNumber('dni', $number);
            $encryption = app(TenantEncryption::class);

            $patient->document_type = 'dni';
            $patient->document_number = $number;
            $patient->document_hash = $encryption->blindIndex($tenantId, PatientIdentity::normalizedDocument('dni', $number));
            $patient->clinical_record_number = $record;
            $patient->clinical_record_hash = $encryption->blindIndex($tenantId, $record);
        });
    }

    /**
     * @return HasMany<LegalRepresentative, $this>
     */
    public function representatives(): HasMany
    {
        return $this->hasMany(LegalRepresentative::class);
    }

    /**
     * @return HasMany<Consent, $this>
     */
    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    /**
     * Consentimiento `vigente` (a lo sumo uno, índice único parcial de SDD §2.5).
     *
     * @return HasOne<Consent, $this>
     */
    public function currentConsent(): HasOne
    {
        return $this->hasOne(Consent::class)->where('status', 'vigente');
    }

    /**
     * Edad en años cumplidos a la fecha de hoy de la clínica (RF-059).
     */
    public function ageYears(): int
    {
        return (int) $this->birth_date->diffInYears(ClinicClock::for($this->tenant)->now()->startOfDay());
    }

    /**
     * RN-12: menor de 18 años a la fecha indicada (por defecto, hoy en la zona de la clínica).
     */
    public function isMinorOn(?string $date = null): bool
    {
        $on = $date ?? ClinicClock::for($this->tenant)->now()->toDateString();

        return $this->birth_date->copy()->addYears(18)->toDateString() > $on;
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
