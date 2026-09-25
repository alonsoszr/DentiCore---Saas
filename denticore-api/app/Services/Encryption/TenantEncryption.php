<?php

namespace App\Services\Encryption;

use App\Models\EncryptionKey;
use App\Models\Tenant;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * Claves de cifrado por clínica (technical_specs.md §2.3 punto 4, §3.2).
 *
 * Cada tenant tiene una clave AES-256 propia. En `encryption_keys.key_ciphertext` se
 * guarda cifrada con la master key del sistema, que en esta implementación es APP_KEY
 * (vía el encrypter de Laravel). Los campos sensibles se cifran con la clave del tenant
 * dueño del registro, nunca con APP_KEY directamente.
 *
 * Se registra como `scoped` en el contenedor: las claves descifradas se cachean solo
 * durante el request actual.
 */
class TenantEncryption
{
    private const CIPHER = 'aes-256-cbc';

    /**
     * @var array<int, Encrypter>
     */
    private array $encrypters = [];

    /**
     * @var array<int, string>
     */
    private array $blindIndexKeys = [];

    /**
     * Genera y persiste la clave de una clínica recién creada.
     */
    public function generateKeyFor(Tenant $tenant): EncryptionKey
    {
        $key = Encrypter::generateKey(self::CIPHER);

        $encryptionKey = new EncryptionKey([
            'key_ciphertext' => Crypt::encryptString(base64_encode($key)),
            'is_active' => true,
        ]);
        // Asignación explícita: el alta la hace super_admin, sin tenant activo en sesión.
        $encryptionKey->tenant_id = $tenant->id;
        $encryptionKey->save();

        return $encryptionKey;
    }

    public function encrypt(int $tenantId, string $value): string
    {
        return $this->encrypterFor($tenantId)->encryptString($value);
    }

    public function decrypt(int $tenantId, string $payload): string
    {
        return $this->encrypterFor($tenantId)->decryptString($payload);
    }

    /**
     * Índice ciego (HMAC-SHA256) para poder aplicar unicidad y búsqueda exacta sobre un
     * campo cifrado, cuyo texto cifrado cambia en cada cifrado (IV aleatorio). La clave
     * del HMAC se deriva de la clave del tenant, así que el mismo valor en dos clínicas
     * produce hashes distintos.
     */
    public function blindIndex(int $tenantId, string $value): string
    {
        $this->blindIndexKeys[$tenantId] ??= hash_hmac('sha256', 'blind-index', $this->plainKeyFor($tenantId), true);

        return hash_hmac('sha256', trim($value), $this->blindIndexKeys[$tenantId]);
    }

    private function encrypterFor(int $tenantId): Encrypter
    {
        return $this->encrypters[$tenantId] ??= new Encrypter($this->plainKeyFor($tenantId), self::CIPHER);
    }

    private function plainKeyFor(int $tenantId): string
    {
        // Búsqueda explícita por tenant_id: debe funcionar tanto en requests de clínica
        // como en flujos sin tenant activo (super_admin), donde el Global Scope no filtra nada.
        $encryptionKey = EncryptionKey::withoutTenantScope()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if (! $encryptionKey) {
            throw new RuntimeException("La clínica {$tenantId} no tiene una clave de cifrado activa.");
        }

        return base64_decode(Crypt::decryptString($encryptionKey->key_ciphertext));
    }
}
