<?php

namespace App\Support\Encryption;

use App\Modules\Platform\Models\EncryptionKey;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * Cifrado de campos con la clave de cada clínica (SDD §1.7.1, DD-04, DI-06).
 *
 * Formato: `v{version}:{base64(iv ‖ ciphertext ‖ tag)}` con AES-256-GCM, IV de 12 bytes y
 * tag de 16. La versión identifica la clave con la que se descifra durante una rotación.
 * El formato heredado de las fases 0–3 (AES-256-CBC de Laravel, sin versión) todavía se
 * descifra; `encryption:reencrypt-legacy` lo convierte y TASK-038 retira su soporte.
 */
class TenantEncryption
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    private const ENVELOPE = '/^v(\d+):(.+)$/s';

    public function __construct(private KeyRing $keys, private BlindIndex $blindIndex) {}

    /**
     * Genera la clave v1 de una clínica recién creada.
     */
    public function generateKeyFor(Tenant $tenant): EncryptionKey
    {
        $key = random_bytes(32);

        // El alta la hace super_admin, sin clínica activa: la clave se crea en el contexto de
        // la clínica nueva, que es de donde BelongsToTenant toma el tenant_id.
        return TenantContext::run($tenant, fn (): EncryptionKey => EncryptionKey::create([
            'key_ciphertext' => Crypt::encryptString(base64_encode($key)),
            'version' => 1,
            'status' => 'activa',
            'is_active' => true,
        ]));
    }

    public function encrypt(int $tenantId, string $value): string
    {
        $version = $this->keys->activeVersion($tenantId);
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $value,
            self::CIPHER,
            $this->keys->encryptionKey($tenantId, $version),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH,
        );

        return "v{$version}:".base64_encode($iv.$ciphertext.$tag);
    }

    /**
     * @throws DecryptException
     */
    public function decrypt(int $tenantId, string $payload): string
    {
        if (! preg_match(self::ENVELOPE, $payload, $matches)) {
            return $this->decryptLegacy($tenantId, $payload);
        }

        $binary = base64_decode($matches[2], true);

        if ($binary === false || strlen($binary) < self::IV_LENGTH + self::TAG_LENGTH) {
            throw new DecryptException('El texto cifrado no tiene un formato válido.');
        }

        $plaintext = openssl_decrypt(
            substr($binary, self::IV_LENGTH, -self::TAG_LENGTH),
            self::CIPHER,
            $this->keys->encryptionKey($tenantId, (int) $matches[1]),
            OPENSSL_RAW_DATA,
            substr($binary, 0, self::IV_LENGTH),
            substr($binary, -self::TAG_LENGTH),
        );

        if ($plaintext === false) {
            throw new DecryptException('No se pudo descifrar el valor con la clave de la clínica.');
        }

        return $plaintext;
    }

    /**
     * El valor ya está en el formato versionado `v{n}:`.
     */
    public function isCurrentFormat(string $payload): bool
    {
        return preg_match(self::ENVELOPE, $payload) === 1;
    }

    public function blindIndex(int $tenantId, string $value): string
    {
        return $this->blindIndex->compute($tenantId, $value);
    }

    /**
     * Formato de las fases 0–3: Encrypter de Laravel (AES-256-CBC + MAC) con la clave v1.
     *
     * @throws DecryptException
     */
    private function decryptLegacy(int $tenantId, string $payload): string
    {
        return (new Encrypter($this->keys->rawKey($tenantId, 1), 'aes-256-cbc'))->decryptString($payload);
    }
}
