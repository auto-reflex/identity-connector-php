<?php

use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\ProvisionedOrganization;
use AutoGteck\IdentityConnector\Events\OrganizationOwnerJoined;
use AutoGteck\IdentityConnector\Facades\Identity;
use AutoGteck\IdentityConnector\Webhooks\WebhookSignature;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

const PROV_OWNER = '01J0USER00000000000000000A';

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
    $organization = Identity::client()->provisionOrganization('pro-1', 'Camille@Example.test', 'Débosselage Durand', 'fr');

    expect($organization)->toBeInstanceOf(ProvisionedOrganization::class)
        ->and($organization->reference)->toBe('pro-1')
        ->and($organization->state)->toBe(ProvisionedOrganization::PENDING)
        ->and($organization->isActive())->toBeFalse()
        ->and($organization->ownerUserId)->toBeNull()
        ->and($this->identity->provisioned()['pro-1'])->toMatchArray(['email' => 'camille@example.test', 'name' => 'Débosselage Durand', 'locale' => 'fr', 'sent' => 1]);
});

it('re-sends the invitation when called again, without creating a second organization', function () {
    $first = Identity::client()->provisionOrganization('pro-1', 'a@example.test', 'Durand');
    $second = Identity::client()->provisionOrganization('pro-1', 'b@example.test', 'Durand');

    expect($second->organizationId)->toBe($first->organizationId)
        ->and($this->identity->provisioned())->toHaveCount(1)
        ->and($this->identity->provisioned()['pro-1'])->toMatchArray(['email' => 'b@example.test', 'sent' => 2]);
});

it('relays the arrival of the owner as an event, and the organization becomes readable as active', function () {
    Event::fake([OrganizationOwnerJoined::class]);
    Identity::client()->provisionOrganization('pro-1', 'camille@example.test', 'Durand');

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
        Identity::client()->provisionOrganization('pro-1', 'not-an-email', 'D');
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
