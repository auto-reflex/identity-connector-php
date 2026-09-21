<?php

use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\IdentityUnavailable;
use AutoGteck\IdentityConnector\Client\LegalIdentity;
use AutoGteck\IdentityConnector\Client\LegalIdentityRejected;
use AutoGteck\IdentityConnector\Client\ProvisionedOrganization;
use AutoGteck\IdentityConnector\Client\RegistryUnavailable;
use AutoGteck\IdentityConnector\Events\OrganizationOwnerJoined;
use AutoGteck\IdentityConnector\Events\OrganizationUpdated;
use AutoGteck\IdentityConnector\Facades\Identity;
use AutoGteck\IdentityConnector\Webhooks\WebhookSignature;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

const PROV_OWNER = '01J0USER00000000000000000A';
const PROV_SIRET = '35600000000048';

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('map-api')->serviceClient('map-api-service', 'service-secret');
    config([
        'identity-connector.service.client_id' => 'map-api-service',
        'identity-connector.service.client_secret' => 'service-secret',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

it('provisions an organization and reports it as pending until the owner joins', function () {
    $organization = Identity::client()->provisionOrganization('pro-1', 'Camille@Example.test', 'Débosselage Durand', PROV_SIRET, 'fr');

    expect($organization)->toBeInstanceOf(ProvisionedOrganization::class)
        ->and($organization->reference)->toBe('pro-1')
        ->and($organization->state)->toBe(ProvisionedOrganization::PENDING)
        ->and($organization->isActive())->toBeFalse()
        ->and($organization->ownerUserId)->toBeNull()
        ->and($this->identity->provisioned()['pro-1'])->toMatchArray(['email' => 'camille@example.test', 'name' => 'Débosselage Durand', 'locale' => 'fr', 'sent' => 1]);
});

it('re-sends the invitation when called again, without creating a second organization', function () {
    $first = Identity::client()->provisionOrganization('pro-1', 'a@example.test', 'Durand', PROV_SIRET);
    $second = Identity::client()->provisionOrganization('pro-1', 'b@example.test', 'Durand', PROV_SIRET);

    expect($second->organizationId)->toBe($first->organizationId)
        ->and($this->identity->provisioned())->toHaveCount(1)
        ->and($this->identity->provisioned()['pro-1'])->toMatchArray(['email' => 'b@example.test', 'sent' => 2]);
});

it('relays the arrival of the owner as an event, and the organization becomes readable as active', function () {
    Event::fake([OrganizationOwnerJoined::class]);
    Identity::client()->provisionOrganization('pro-1', 'camille@example.test', 'Durand', PROV_SIRET);

    $webhook = $this->identity->ownerJoins('pro-1', PROV_OWNER, '01J0EVENT0000000000000000A');
    $this->call('POST', '/identity/webhooks', [], [], [], $webhook['server'], $webhook['body'])->assertNoContent();

    $organization = Identity::client()->provisionedOrganization('pro-1');
    Event::assertDispatched(OrganizationOwnerJoined::class, fn (OrganizationOwnerJoined $event) => $event->organizationId === $organization->organizationId
        && $event->userId === PROV_OWNER
        && $event->reference === 'pro-1'
        && $event->eventId === '01J0EVENT0000000000000000A');
    expect($organization->isActive())->toBeTrue()->and($organization->ownerUserId)->toBe(PROV_OWNER);
});

it('answers null for a reference it does not know', function () {
    expect(Identity::client()->provisionedOrganization('nope'))->toBeNull();
});

it('surfaces validation errors as a typed rejection', function () {
    try {
        Identity::client()->provisionOrganization('pro-1', 'not-an-email', 'D', PROV_SIRET);
        $this->fail('A rejection was expected.');
    } catch (IdentityRejected $rejected) {
        expect($rejected->status)->toBe(422)->and($rejected->body['errors'])->toHaveKeys(['email', 'organization_name']);
    }
});

it('refuses an owner event without user or reference', function () {
    $body = json_encode(['id' => 'a', 'type' => 'organization.owner_joined', 'version' => 1, 'occurred_at' => now()->toIso8601String(), 'data' => ['organization_id' => '01J0ORG0000000000000000001']]);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDENTITY_SIGNATURE' => WebhookSignature::header($body, 'test-webhook-secret-0123456789abcdef0123', now()->getTimestamp())];

    $this->call('POST', '/identity/webhooks', [], [], [], $server, $body)->assertStatus(400);
});

describe('legal identity (AR-075)', function () {
    it('sends the SIRET and exposes the verified legal identity of the organization', function () {
        $organization = Identity::client()->provisionOrganization('pro-1', 'camille@example.test', 'Débosselage Durand', '356 000 000 00048');

        expect($organization->legal)->toBeInstanceOf(LegalIdentity::class)
            ->and($organization->legal->siret)->toBe(PROV_SIRET)
            ->and($organization->legal->siren)->toBe('356000000')
            ->and($organization->legal->legalName)->toBe('Débosselage Durand')
            ->and($organization->legal->address)->toBe(['line' => '12 RUE DES ATELIERS', 'postal_code' => '69003', 'city' => 'LYON'])
            ->and($organization->legal->verified)->toBeTrue()
            ->and($organization->legal->registry)->toBe('fr_sirene')
            ->and(Identity::client()->provisionedOrganization('pro-1')->legal->siret)->toBe(PROV_SIRET);
    });

    it('does not verify the SIRET again when the call is replayed', function () {
        Identity::client()->provisionOrganization('pro-1', 'a@example.test', 'Durand', PROV_SIRET);
        $this->identity->registryUnavailable();

        expect(Identity::client()->provisionOrganization('pro-1', 'b@example.test', 'Durand', PROV_SIRET)->legal->siret)->toBe(PROV_SIRET);
    });

    it('turns a refused SIRET into a typed form error, not an outage', function (string $code, int $status) {
        $siret = '80000000000011';
        match ($code) {
            'siret_taken' => $this->identity->siretTaken($siret),
            'siret_not_found' => $this->identity->siretNotFound($siret),
            'siret_inactive' => $this->identity->siretInactive($siret),
            'siret_invalid' => $siret = '1234',
        };

        try {
            Identity::client()->provisionOrganization('pro-1', 'camille@example.test', 'Durand', $siret);
            $this->fail('A rejection was expected.');
        } catch (LegalIdentityRejected $rejected) {
            expect($rejected)->toBeInstanceOf(IdentityRejected::class)->and($rejected->error)->toBe($code)->and($rejected->status)->toBe($status);
        }

        expect($this->identity->provisioned())->toBe([]);
    })->with([['siret_invalid', 422], ['siret_not_found', 422], ['siret_inactive', 422], ['siret_taken', 409]]);

    it('tells a registry outage apart from a form error, and creates nothing', function () {
        $this->identity->registryUnavailable();

        try {
            Identity::client()->provisionOrganization('pro-1', 'camille@example.test', 'Durand', PROV_SIRET);
            $this->fail('An outage was expected.');
        } catch (RegistryUnavailable $unavailable) {
            expect($unavailable)->toBeInstanceOf(IdentityUnavailable::class);
        }

        expect($this->identity->provisioned())->toBe([]);
    });

    it('relays organization.updated with the organization and what changed', function () {
        Event::fake([OrganizationUpdated::class]);

        $webhook = $this->identity->webhook('organization.updated', '01J0ORG0000000000000000001', '01J0EVENT0000000000000000B');
        $this->call('POST', '/identity/webhooks', [], [], [], $webhook['server'], $webhook['body'])->assertNoContent();

        Event::assertDispatched(OrganizationUpdated::class, fn (OrganizationUpdated $event) => $event->organizationId === '01J0ORG0000000000000000001'
            && $event->changed === ['legal']
            && $event->eventId === '01J0EVENT0000000000000000B');
    });

    it('refuses an organization.updated event without a list of changes', function (mixed $changed) {
        $webhook = $this->identity->webhook('organization.updated', '01J0ORG0000000000000000001', data: ['organization_id' => '01J0ORG0000000000000000001', 'changed' => $changed]);

        $this->call('POST', '/identity/webhooks', [], [], [], $webhook['server'], $webhook['body'])->assertStatus(400);
    })->with(['missing' => [null], 'string' => ['legal'], 'object' => [['a' => 'legal']], 'number' => [[1]]]);
});
