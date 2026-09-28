<?php

namespace App\Support\Encryption;

use App\Modules\Platform\Models\EncryptionKey;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Claves de cifrado de cada clínica por versión (SDD §1.7.1, DD-04, RF-048). La clave de
 * la clínica se guarda cifrada con la clave maestra (APP_KEY) en `encryption_keys`; aquí se
 * descifra y se cachea solo durante la solicitud (servicio `scoped`).
 *
 * De cada clave de clínica se derivan con HKDF-SHA256 la clave de cifrado de campos
 * (`enc`) y la del índice ciego (`bidx`).
 */
class KeyRing
{
    /**
     * @var array<string, string>
     */
    private array $rawKeys = [];

    /**
     * @var array<int, int>
     */
    private array $activeVersions = [];

    public function activeVersion(int $tenantId): int
    {
        return $this->activeVersions[$tenantId] ??= (int) ($this->withinTenant($tenantId, fn () => EncryptionKey::query()
            ->where('status', 'activa')
            ->value('version'))
            ?? throw new RuntimeException("La clínica {$tenantId} no tiene una clave de cifrado activa."));
    }

    public function encryptionKey(int $tenantId, int $version): string
    {
        return hash_hkdf('sha256', $this->rawKey($tenantId, $version), 32, 'enc');
    }

    public function blindIndexKey(int $tenantId, int $version): string
    {
        return hash_hkdf('sha256', $this->rawKey($tenantId, $version), 32, 'bidx');
    }

    /**
     * Clave AES-256 de la clínica sin derivar. Solo la usa el descifrado del formato heredado
     * de las fases 0–3 (AES-256-CBC con la clave directa), que TASK-038 retira.
     */
    public function rawKey(int $tenantId, int $version): string
    {
        return $this->rawKeys["{$tenantId}:{$version}"] ??= $this->loadRawKey($tenantId, $version);
    }

    private function loadRawKey(int $tenantId, int $version): string
    {
        $ciphertext = $this->withinTenant($tenantId, fn () => EncryptionKey::query()
            ->where('version', $version)
            ->value('key_ciphertext'));

        if ($ciphertext === null) {
            throw new RuntimeException("La clínica {$tenantId} no tiene la versión {$version} de su clave de cifrado.");
        }

        return base64_decode(Crypt::decryptString($ciphertext));
    }

    /**
     * `encryption_keys` es tabla de clínica (Global Scope y RLS): la clave de un registro se lee
     * en el contexto de su propia clínica. Con otra clínica activa se rechaza la lectura.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $query
     * @return TResult
     */
    private function withinTenant(int $tenantId, Closure $query): mixed
    {
        $current = TenantContext::id();

        if ($current === $tenantId) {
            return $query();
        }

        if ($current !== null) {
            throw new RuntimeException("No se puede usar la clave de la clínica {$tenantId} desde la clínica {$current}.");
        }

        return TenantContext::run($tenantId, $query);
    }
}
