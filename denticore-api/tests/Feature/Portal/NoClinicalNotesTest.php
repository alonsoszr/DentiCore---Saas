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
    [$user, $patient] = PortalFixtures::holder($tenant);
    PortalFixtures::recordedFinding($tenant, $patient);
    $budget = PortalFixtures::issuedBudget($tenant, $patient);
    $this->actingWithToken($user);

    $uris = [
        '/api/v1/portal/subjects',
        "/api/v1/portal/patients/{$patient->uuid}/consents",
        "/api/v1/portal/patients/{$patient->uuid}/consents/preview",
        "/api/v1/portal/patients/{$patient->uuid}/teeth/36/history",
        "/api/v1/portal/patients/{$patient->uuid}/budgets",
        "/api/v1/portal/budgets/{$budget}",
        "/api/v1/portal/budgets/{$budget}/pdf",
    ];

    foreach ($uris as $uri) {
        expect(clinicalNoteKeysIn($this->getJson($uri)->assertOk()->json()))->toBe([], $uri);
    }
})->group('DI-13', 'RF-179');
