<?php

/*
 * Consentimiento desde el portal (TASK-088; CUS-17 FA-1; RF-065, RF-066, RN-12, CA-17.1): la
 * confirmación es la sesión autenticada; el canal es `portal` y no hay personal que asista.
 */

use App\Modules\Patients\Models\Consent;
use App\Modules\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\Support\PortalFixtures;

function portalConsentKey(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

it('previews the consent text and the purposes offered by the clinic', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant, withConsent: false);
    $this->actingWithToken($user);

    $this->getJson("/api/v1/portal/patients/{$patient->uuid}/consents/preview")
        ->assertOk()
        ->assertJsonPath('data.granted_by', 'titular')
        ->assertJsonPath('data.representative', null)
        ->assertJsonStructure(['data' => ['template_version', 'text', 'text_sha256', 'available_purposes']]);
})->group('CUS-17', 'RF-065');

it('records the consent granted from the portal with the portal channel', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant, withConsent: false);
    $this->actingWithToken($user);

    $this->postJson("/api/v1/portal/patients/{$patient->uuid}/consents", [
        'purpose_care' => true,
        'purpose_notifications' => true,
    ], portalConsentKey())
        ->assertCreated()
        ->assertJsonPath('data.channel', 'portal')
        ->assertJsonPath('data.granted_by', 'titular')
        ->assertJsonPath('data.purpose_notifications', true)
        ->assertJsonPath('data.purpose_surveys', false);

    $consent = TenantContext::run($tenant, fn () => Consent::query()->where('patient_id', $patient->id)->sole());
    expect($consent->channel)->toBe('portal')
        ->and($consent->assisted_by)->toBeNull()
        ->and($consent->status)->toBe('vigente');

    $this->getJson("/api/v1/portal/patients/{$patient->uuid}/consents")
        ->assertOk()
        ->assertJsonPath('data.0.id', $consent->uuid);
})->group('CUS-17', 'RF-065', 'RF-066');

it('requires the care purpose and ignores a channel sent by the client', function () {
    $tenant = Tenant::factory()->create();
    [$user, $patient] = PortalFixtures::holder($tenant, withConsent: false);
    $this->actingWithToken($user);

    $this->postJson("/api/v1/portal/patients/{$patient->uuid}/consents", ['purpose_care' => false], portalConsentKey())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('purpose_care');
    $this->postJson("/api/v1/portal/patients/{$patient->uuid}/consents", ['purpose_care' => true, 'channel' => 'presencial'], portalConsentKey())
        ->assertCreated()
        ->assertJsonPath('data.channel', 'portal');
})->group('CUS-17', 'RN-10');

it('lets the representative grant the consent of the minor through their own representation', function () {
    $tenant = Tenant::factory()->create();
    $minor = PortalFixtures::minor($tenant);
    $representative = PortalFixtures::representative($tenant, $minor);
    $this->actingWithToken($representative);

    $this->getJson("/api/v1/portal/patients/{$minor->uuid}/consents/preview")->assertOk()->assertJsonPath('data.granted_by', 'representante');
    $this->postJson("/api/v1/portal/patients/{$minor->uuid}/consents", ['purpose_care' => true], portalConsentKey())
        ->assertCreated()
        ->assertJsonPath('data.granted_by', 'representante')
        ->assertJsonPath('data.representative_id', PortalFixtures::representation($tenant, $minor)->uuid);
})->group('CUS-17', 'RN-12', 'CA-17.3');

it('forbids a minor holder from granting and hides other patients', function () {
    $tenant = Tenant::factory()->create();
    [$minorUser, $minor] = PortalFixtures::holder($tenant, ['birth_date' => '2012-04-20'], withConsent: false);
    PortalFixtures::representative($tenant, $minor);
    [, $other] = PortalFixtures::holder($tenant, withConsent: false);
    $this->actingWithToken($minorUser);

    $this->postJson("/api/v1/portal/patients/{$minor->uuid}/consents", ['purpose_care' => true], portalConsentKey())
        ->assertForbidden()
        ->assertJsonPath('rule', 'RN-12');
    $this->getJson("/api/v1/portal/patients/{$other->uuid}/consents")->assertNotFound();
    $this->getJson("/api/v1/portal/patients/{$other->uuid}/consents/preview")->assertNotFound();
})->group('RN-12', 'RN-03');
