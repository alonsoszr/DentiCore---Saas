<?php

namespace App\Models;

use App\Casts\TenantEncrypted;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ficha del paciente (technical_specs.md §3.4). document_id y phone se cifran en reposo
 * con la clave de la clínica (cast TenantEncrypted); tenant_id y user_id nunca son
 * asignables masivamente.
 */
#[Fillable(['document_id', 'first_name', 'last_name', 'birth_date', 'phone', 'email', 'medical_history'])]
#[Hidden(['document_id_hash'])]
class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use BelongsToTenant, HasFactory, HasUuid;

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

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
