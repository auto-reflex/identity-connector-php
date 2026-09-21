<?php

use AutoGteck\IdentityConnector\Client\IdentityUnavailable;
use Carbon\Carbon;

const ORG_PERSON = '01J0USER00000000000000000A';

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autotrackly-api')
        ->user(ORG_PERSON)
        ->organization('01J0ORG0000000000000000001', 'Garage Dupont', [ORG_PERSON => 'admin'])
        ->organization('01J0ORG0000000000000000009', 'Autre', ['01J0OTHER0000000000000000B' => 'owner']);
    $this->headers = fn () => ['Authorization' => 'Bearer '.$this->identity->tokenFor(ORG_PERSON, ['profile', 'email'], ['client_id' => 'autotrackly-mobile'])];
});

afterEach(fn () => Carbon::setTestNow());

it('lets a product API read the organizations of the connected person through the facade', function () {
    $response = $this->getJson('/api/organizations', ($this->headers)())->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toMatchArray(['id' => '01J0ORG0000000000000000001', 'name' => 'Garage Dupont', 'role' => 'admin']);
});

it('answers 404 for an organization the person is not part of', function () {
    $this->getJson('/api/organizations/01J0ORG0000000000000000001', ($this->headers)())->assertOk()->assertJsonPath('data.role', 'admin');
    $this->getJson('/api/organizations/01J0ORG0000000000000000009', ($this->headers)())->assertNotFound();
});

it('lets the product decide how to degrade when Identity is down: the exception is typed', function () {
    $this->getJson('/api/me', ($this->headers)())->assertOk();
    $this->identity->goDown();
    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/api/organizations', ($this->headers)()))->toThrow(IdentityUnavailable::class);
});

it('reads the type and the legal identity of an organization, null when it has none', function () {
    $this->identity->organization('01J0ORG0000000000000000002', 'Débosselage Durand', [ORG_PERSON => 'owner'], legal: $this->identity->legalBlock('35600000000048'))
        ->organization('01J0ORG0000000000000000003', 'Club Passion', [ORG_PERSON => 'member'], kind: 'association');

    $organizations = collect($this->getJson('/api/organizations', ($this->headers)())->assertOk()->json('data'))->keyBy('id');

    expect($organizations['01J0ORG0000000000000000002']['kind'])->toBe('professional')
        ->and($organizations['01J0ORG0000000000000000002']['legal'])->toMatchArray(['siret' => '35600000000048', 'siren' => '356000000', 'legalName' => 'Débosselage Durand', 'verified' => true])
        ->and($organizations['01J0ORG0000000000000000003']['kind'])->toBe('association')
        ->and($organizations['01J0ORG0000000000000000003']['legal'])->toBeNull();
});
