<?php

/*
 * Contracción de las columnas heredadas (TASK-038; Plan §1.5, RNF-132): ningún archivo de la
 * aplicación vuelve a referirse a lo que se eliminó.
 */

use Symfony\Component\Finder\Finder;

/** Patrón → qué se retiró. `certificate_document_id` y el campo `subscription_plan` de la API son válidos. */
const CONTRACTED_REFERENCES = [
    '/(?<![a-z_])document_id(_hash)?\b/' => 'patients.document_id / document_id_hash',
    '/\bis_active\b/' => 'users.is_active / encryption_keys.is_active',
    '/->subscription_plan\b(?!_)|isDirty\(\'subscription_plan\'\)/' => 'tenants.subscription_plan',
    '/\$tenant->settings\b|\'settings\' => \'array\'/' => 'tenants.settings',
    '/decryptLegacy|reencrypt-legacy|ReencryptLegacy/' => 'formato de cifrado de las fases 0–3',
    '/user_uuid\' => \[/' => 'campo user_uuid de POST /patients (S-14)',
];

it('finds no reference to the contracted legacy columns', function () {
    $files = Finder::create()
        ->files()
        ->in(array_map(fn (string $path) => base_path($path), ['app', 'routes', 'resources', 'database/factories', 'database/seeders']))
        ->name(['*.php', '*.blade.php']);

    $found = [];
    foreach ($files as $file) {
        foreach (CONTRACTED_REFERENCES as $pattern => $removed) {
            if (preg_match($pattern, $file->getContents()) === 1) {
                $found[] = "{$file->getRelativePathname()}: {$removed}";
            }
        }
    }

    expect($found)->toBe([]);
})->group('RNF-132', 'DI-03');
