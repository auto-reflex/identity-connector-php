<?php

use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autotrackly-api')->user('01J0USER00000000000000000A');
    $this->identity->vehicles()->add('01J0VEHICLE0000000000000A1', '01J0USER00000000000000000A', [
        'identity' => ['make' => 'Peugeot', 'model' => '205'],
        'sensitive' => ['vin' => 'VF3ABCDEFGH123456', 'plate' => 'AB-123-CD'],
    ], ['autotrackly' => ['groups' => ['identity', 'sensitive']]]);
    $this->headers = fn (array $scopes) => ['Authorization' => 'Bearer '.$this->identity->tokenFor('01J0USER00000000000000000A', $scopes, ['client_id' => 'autotrackly-mobile'])];
});

afterEach(fn () => Carbon::setTestNow());

it('gives the sensitive group to an owner who asks for it, with the scope, in a product whose ceiling allows it', function () {
    $response = $this->getJson('/api/vehicles/01J0VEHICLE0000000000000A1?fields=identity,sensitive', ($this->headers)(['profile', 'vehicles:sensitive']))->assertOk();

    expect($response->json('data.groups.sensitive'))->toBe(['vin' => 'VF3ABCDEFGH123456', 'plate' => 'AB-123-CD']);
});

it('does not give it without the explicit scope', function () {
    $response = $this->getJson('/api/vehicles/01J0VEHICLE0000000000000A1?fields=identity,sensitive', ($this->headers)(['profile']))->assertOk();

    expect(array_keys($response->json('data.groups')))->toBe(['identity'])->and($response->getContent())->not->toContain('VF3ABC');
});

it('never puts it in a cache, whatever its duration', function () {
    $headers = ($this->headers)(['profile', 'vehicles:sensitive']);
    $url = '/api/vehicles/01J0VEHICLE0000000000000A1?fields=identity,sensitive';

    $this->getJson($url, $headers)->assertOk();
    $this->getJson($url, $headers)->assertOk();

    expect($this->identity->vehicles()->reads())->toBe(2);
    $stored = json_encode((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue(app('cache')->store()->getStore()));
    expect($stored)->not->toContain('VF3ABC')->not->toContain('AB-123');
});

it('does not serve it from a stale copy either: with Identity down, the read fails', function () {
    $headers = ($this->headers)(['profile', 'vehicles:sensitive']);
    $url = '/api/vehicles/01J0VEHICLE0000000000000A1?fields=identity,sensitive';
    $this->getJson($url, $headers)->assertOk();
    $this->identity->goDown();
    Carbon::setTestNow(now()->addSeconds(61));

    $this->getJson($url, ($this->headers)(['profile', 'vehicles:sensitive']))->assertStatus(503)->assertJson(['error' => 'identity_unavailable']);
});
