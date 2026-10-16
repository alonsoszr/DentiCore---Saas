<?php

/*
 * Contrato del portal (DI-13; RF-179; Plan §5.8): ninguna respuesta de las rutas PORT contiene
 * claves de la nota clínica. Cada tarea del portal agrega aquí sus rutas.
 */

use App\Modules\Platform\Models\Tenant;
use Tests\Support\PortalFixtures;

/** Claves de la nota clínica de la atención y de las entradas del odontograma (SDD §2.6). */
const CLINICAL_NOTE_KEYS = [
    'note', 'notes', 'chief_complaint', 'current_illness', 'extraoral_exam', 'intraoral_exam',
    'indications', 'addenda', 'addendum',
];

/**
 * @return list<string> Claves de nota encontradas en cualquier nivel de la respuesta.
 */
function clinicalNoteKeysIn(mixed $payload): array
{
    if (! is_array($payload)) {
        return [];
    }

    $found = [];
    foreach ($payload as $key => $value) {
        if (is_string($key) && in_array($key, CLINICAL_NOTE_KEYS, true)) {
            $found[] = $key;
        }
        array_push($found, ...clinicalNoteKeysIn($value));
    }

    return $found;
}

it('serves no clinical note keys from the portal routes', function () {
    $tenant = Tenant::factory()->create();
    [$user] = PortalFixtures::holder($tenant);
    $this->actingWithToken($user);

    foreach (['/api/v1/portal/subjects'] as $uri) {
        expect(clinicalNoteKeysIn($this->getJson($uri)->assertOk()->json()))->toBe([], $uri);
    }
})->group('DI-13', 'RF-179');
