<?php

namespace App\Support\Encryption\Commands;

use App\Modules\Patients\Models\Patient;
use App\Modules\Platform\Models\Tenant;
use App\Support\Encryption\TenantEncryption;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Comando de fontanería de TASK-013 (RNF-132, patrón expandir/contraer): recifra al formato
 * `v1:` los campos cifrados con el formato heredado de las fases 0–3 y recalcula su índice
 * ciego. Solo toca valores en formato heredado, así que repetirlo no cambia nada.
 */
class ReencryptLegacyCommand extends Command
{
    /**
     * Modelos con campos cifrados (cast TenantEncrypted). El índice ciego lo recalcula el
     * propio cast al volver a asignar el valor.
     *
     * @var array<class-string<Model>, list<string>>
     */
    private const ENCRYPTED_FIELDS = [
        Patient::class => ['document_id', 'phone'],
    ];

    protected $signature = 'encryption:reencrypt-legacy';

    protected $description = 'Recifra al formato v1 (AES-256-GCM) los campos cifrados con el formato heredado';

    public function handle(TenantEncryption $encryption): int
    {
        $converted = 0;

        foreach (Tenant::query()->orderBy('id')->cursor() as $tenant) {
            $converted += TenantContext::run($tenant, fn (): int => $this->convertTenant($encryption));
        }

        $this->info("Registros recifrados: {$converted}");

        return self::SUCCESS;
    }

    private function convertTenant(TenantEncryption $encryption): int
    {
        $converted = 0;

        foreach (self::ENCRYPTED_FIELDS as $modelClass => $fields) {
            $modelClass::query()->chunkById(500, function (Collection $models) use ($encryption, $fields, &$converted): void {
                foreach ($models as $model) {
                    if ($this->reencrypt($model, $fields, $encryption)) {
                        $converted++;
                    }
                }
            });
        }

        return $converted;
    }

    /**
     * @param  list<string>  $fields
     */
    private function reencrypt(Model $model, array $fields, TenantEncryption $encryption): bool
    {
        $legacyFields = array_filter($fields, function (string $field) use ($model, $encryption): bool {
            $stored = $model->getRawOriginal($field);

            return is_string($stored) && ! $encryption->isCurrentFormat($stored);
        });

        if ($legacyFields === []) {
            return false;
        }

        foreach ($legacyFields as $field) {
            // Leer descifra el formato heredado; asignar cifra en v1 (y recalcula el índice).
            $model->setAttribute($field, $model->getAttribute($field));
        }

        $model->timestamps = false;
        $model->saveQuietly();

        return true;
    }
}
