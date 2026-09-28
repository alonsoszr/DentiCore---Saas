<?php

namespace App\Support\Encryption;

use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Cifra un atributo en reposo con la clave de la clínica dueña del registro
 * (SDD §1.7.1, DD-04).
 *
 * Uso: 'phone' => TenantEncrypted::class, o con índice ciego:
 * 'document_id' => TenantEncrypted::class.':document_id_hash'.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class TenantEncrypted implements CastsAttributes
{
    public function __construct(private ?string $blindIndexColumn = null) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(TenantEncryption::class)->decrypt($this->tenantId($attributes), $value);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return $this->blindIndexColumn
                ? [$key => null, $this->blindIndexColumn => null]
                : [$key => null];
        }

        $encryption = app(TenantEncryption::class);
        $tenantId = $this->tenantId($attributes);

        $stored = [$key => $encryption->encrypt($tenantId, (string) $value)];

        if ($this->blindIndexColumn) {
            $stored[$this->blindIndexColumn] = $encryption->blindIndex($tenantId, (string) $value);
        }

        return $stored;
    }

    /**
     * El tenant del registro si ya está asignado; si no (alta en curso), el tenant activo
     * de la sesión, que es el que BelongsToTenant asignará al guardar.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function tenantId(array $attributes): int
    {
        if (! empty($attributes['tenant_id'])) {
            return (int) $attributes['tenant_id'];
        }

        return TenantContext::id()
            ?? throw new RuntimeException('No se puede cifrar sin una clínica asociada al registro.');
    }
}
