<?php

namespace App\Modules\Odontogram\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Odontogram\Policies\OdontogramEntryPolicy;
use App\Modules\Patients\Models\Patient;
use App\Support\Database\HasUuid;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\OdontogramEntryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Entrada del odontograma (SDD §2.6 `odontogram_entries`; DD-05, DD-46, RN-17, RN-22, RN-23).
 * Inmutable: un error se corrige con otra entrada (`correccion`), nunca con UPDATE o DELETE.
 * La tabla está particionada por año de `recorded_at`; la cadena de hashes la calcula la BD.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $chain_patient_id
 * @property int $attention_id
 * @property int|null $initial_odontogram_id
 * @property string $entry_type
 * @property int $tooth
 * @property int|null $tooth_end
 * @property list<string> $surfaces
 * @property int|null $finding_id
 * @property int|null $finding_state_id
 * @property string|null $color
 * @property string $origin
 * @property int|null $ai_suggestion_id
 * @property int|null $performed_procedure_id
 * @property int|null $corrects_entry_id
 * @property string|null $correction_kind
 * @property string|null $correction_reason
 * @property string|null $note
 * @property int $author_id
 * @property string $author_cop
 * @property Carbon $recorded_at
 * @property string $prev_hash
 * @property string $hash
 * @property-read Patient $patient
 * @property-read Attention $attention
 * @property-read FindingCatalog|null $finding
 * @property-read FindingState|null $findingState
 * @property-read User $author
 * @property-read OdontogramEntry|null $correctedEntry
 */
#[UseFactory(OdontogramEntryFactory::class)]
#[UsePolicy(OdontogramEntryPolicy::class)]
class OdontogramEntry extends Model
{
    /** @use HasFactory<OdontogramEntryFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'tooth' => 'integer',
            'tooth_end' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * `surfaces` es text[] de PostgreSQL ("{M,O}"); las letras de RN-18 no requieren comillas.
     *
     * @return Attribute<list<string>, list<string>|string>
     */
    protected function surfaces(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null || $value === '{}' ? [] : explode(',', trim($value, '{}')),
            set: fn (array|string $value): string => is_string($value) ? $value : '{'.implode(',', $value).'}',
        );
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<Attention, $this>
     */
    public function attention(): BelongsTo
    {
        return $this->belongsTo(Attention::class);
    }

    /**
     * @return BelongsTo<FindingCatalog, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(FindingCatalog::class, 'finding_id');
    }

    /**
     * @return BelongsTo<FindingState, $this>
     */
    public function findingState(): BelongsTo
    {
        return $this->belongsTo(FindingState::class, 'finding_state_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Entrada que corrige (RN-23); se valida en el servicio, sin FK hacia la tabla particionada.
     *
     * @return BelongsTo<OdontogramEntry, $this>
     */
    public function correctedEntry(): BelongsTo
    {
        return $this->belongsTo(OdontogramEntry::class, 'corrects_entry_id');
    }

    public function auditPatientUuid(): ?string
    {
        return $this->patient->uuid;
    }
}
