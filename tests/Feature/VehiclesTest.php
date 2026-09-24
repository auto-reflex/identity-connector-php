<?php

use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\IdentityUnavailable;
use AutoGteck\IdentityConnector\Client\VehicleClient;
use AutoGteck\IdentityConnector\Events\VehicleDeleted;
use AutoGteck\IdentityConnector\Events\VehicleUnlinked;
use AutoGteck\IdentityConnector\Facades\Identity;
use AutoGteck\IdentityConnector\IdentityManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;
use Workbench\App\Models\VehicleNote;

const V_OWNER = '01J0USER00000000000000000A';
const V_OTHER = '01J0USER00000000000000000B';
const V_ID = '01J0VEHICLE0000000000000A1';

function vehicleHeaders(TestCase $test, string $user = V_OWNER, array $scopes = ['profile', 'email']): array
{
    return ['Authorization' => 'Bearer '.$test->identity->tokenFor($user, $scopes, ['client_id' => 'autodonuts-mobile'])];
}

function serviceHeaders(TestCase $test): array
{
    return ['Authorization' => 'Bearer '.$test->identity->tokenFor('autodonuts-api-service', ['vehicles:read'], ['client_id' => 'autodonuts-api-service'])];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autodonuts-api')->user(V_OWNER)->user(V_OTHER, name: 'Alex Martin')->serviceClient('autodonuts-api-service', 'service-secret');
    config(['identity-connector.service.client_id' => 'autodonuts-api-service', 'identity-connector.service.client_secret' => 'service-secret']);
    $this->vehicles = $this->identity->vehicles();
    $this->vehicles->add(V_ID, V_OWNER, [
        'identity' => ['make' => 'Peugeot', 'model' => '205', 'trim' => 'GTI', 'year' => 1991],
        'specs' => ['color' => 'Rouge'],
        'usage' => ['mileage_km' => 182000, 'mileage_read_on' => '2026-09-01'],
        'sensitive' => ['vin' => 'VF3ABCDEFGH123456', 'plate' => 'AB-123-CD'],
    ], ['autodonuts' => ['groups' => ['identity', 'specs', 'usage', 'sensitive'], 'visibility' => 'private']]);
});

afterEach(fn () => Carbon::setTestNow());

describe('the garage and a vehicle, for the connected person', function () {
    it('lists the garage with the groups the link grants, within the ceiling of the product', function () {
        $data = $this->getJson('/api/garage', vehicleHeaders($this))->assertOk()->json('data');

        expect($data)->toHaveCount(1)->and($data[0]['id'])->toBe(V_ID)->and(array_keys($data[0]['groups']))->toBe(['identity', 'specs', 'usage'])
            ->and($data[0]['role'])->toBe('owner')->and($data[0]['link']['groups'])->toBe(['identity', 'specs', 'usage', 'sensitive'])
            ->and($data[0]['stale'])->toBeFalse();
    });

    it('never gives the sensitive group to a product whose ceiling does not allow it, even when asked', function () {
        $response = $this->getJson('/api/vehicles/'.V_ID.'?fields=identity,sensitive', vehicleHeaders($this, scopes: ['profile', 'vehicles:sensitive']))->assertOk();

        expect(array_keys($response->json('data.groups')))->toBe(['identity'])->and($response->getContent())->not->toContain('VF3ABC')->not->toContain('AB-123');
    });

    it('answers 404 for a vehicle the person cannot read', function () {
        $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this, V_OTHER))->assertNotFound();
        $this->getJson('/api/vehicles/01J0VEHICLE0000000000000ZZ', vehicleHeaders($this))->assertNotFound();
    });

    it('creates a vehicle, linked to the product, and finds it in the garage', function () {
        $created = $this->postJson('/api/vehicles', ['identity' => ['make' => 'Renault', 'model' => '5 Turbo'], 'link' => ['groups' => ['identity'], 'visibility' => 'public']], vehicleHeaders($this))->assertCreated()->json('data');

        $garage = collect($this->getJson('/api/garage', vehicleHeaders($this))->json('data'))->pluck('id')->all();

        expect($created['version'])->toBe(1)->and($created['groups']['identity']['make'])->toBe('Renault')->and($garage)->toContain($created['id']);
    });

    it('updates with the version read, and refuses a stale version with the current one', function () {
        $updated = $this->patchJson('/api/vehicles/'.V_ID, ['version' => 1, 'data' => ['specs' => ['color' => 'Bleu']]], vehicleHeaders($this))->assertOk();
        expect($updated->json('data.version'))->toBe(2)->and($updated->json('data.groups.specs.color'))->toBe('Bleu');

        $this->patchJson('/api/vehicles/'.V_ID, ['version' => 1, 'data' => ['specs' => ['color' => 'Vert']]], vehicleHeaders($this))->assertStatus(412)
            ->assertJson(['error' => 'precondition_failed', 'current_version' => 2]);
    });

    it('refuses to write a group the link does not grant', function () {
        $this->vehicles->add('01J0VEHICLE0000000000000A2', V_OWNER, ['identity' => ['make' => 'Lada', 'model' => 'Niva']], ['autodonuts' => ['groups' => ['identity']]]);

        $this->patchJson('/api/vehicles/01J0VEHICLE0000000000000A2', ['version' => 1, 'data' => ['specs' => ['color' => 'Vert']]], vehicleHeaders($this))->assertForbidden()->assertJson(['error' => 'group_not_allowed']);
    });

    it('deletes a vehicle, and links or unlinks it for the product', function () {
        $this->deleteJson('/api/vehicles/'.V_ID.'/link', [], vehicleHeaders($this))->assertNoContent();
        expect($this->vehicles->find(V_ID)['links'])->toBe([]);

        $link = $this->putJson('/api/vehicles/'.V_ID.'/link', ['groups' => ['identity', 'specs'], 'visibility' => 'public'], vehicleHeaders($this))->assertOk()->json('data');
        expect($link)->toMatchArray(['product' => 'autodonuts', 'groups' => ['identity', 'specs'], 'visibility' => 'public', 'shareUsage' => false]);

        $this->deleteJson('/api/vehicles/'.V_ID, [], vehicleHeaders($this))->assertNoContent();
        expect($this->vehicles->deleted())->toBe([V_ID]);
    });
});

describe('reading the vehicle of a third party, service to service', function () {
    it('gives only what the link and the visibility allow, for the declared reader', function () {
        $this->vehicles->add('01J0VEHICLE0000000000000A3', V_OTHER, ['identity' => ['make' => 'Lancia', 'model' => 'Delta'], 'specs' => ['color' => 'Blanc'], 'usage' => ['mileage_km' => 90000]], ['autodonuts' => ['groups' => ['identity', 'specs', 'usage'], 'visibility' => 'public']]);
        $this->vehicles->add('01J0VEHICLE0000000000000A4', V_OTHER, ['identity' => ['make' => 'Fiat', 'model' => 'Panda']], ['autodonuts' => ['groups' => ['identity'], 'visibility' => 'private']]);
        $ids = '01J0VEHICLE0000000000000A3,01J0VEHICLE0000000000000A4,'.V_ID;

        $data = $this->getJson('/api/public/vehicles?ids='.$ids, serviceHeaders($this))->assertOk()->json('data');

        expect(collect($data)->pluck('id')->all())->toBe(['01J0VEHICLE0000000000000A3'])->and(array_keys($data[0]['groups']))->toBe(['identity', 'specs']);
    });

    it('applies the product visibility to a declared member, not to an anonymous reader', function () {
        $this->vehicles->add('01J0VEHICLE0000000000000A5', V_OTHER, ['identity' => ['make' => 'Fiat', 'model' => '500']], ['autodonuts' => ['groups' => ['identity'], 'visibility' => 'product']]);

        $anonymous = $this->getJson('/api/public/vehicles?ids=01J0VEHICLE0000000000000A5&reader=anonymous', serviceHeaders($this))->json('data');
        $member = $this->getJson('/api/public/vehicles?ids=01J0VEHICLE0000000000000A5&reader=member', serviceHeaders($this))->json('data');

        expect($anonymous)->toBe([])->and($member)->toHaveCount(1);
    });

    it('refuses to read without a valid declared reader', function () {
        expect(fn () => Identity::vehicleClient()->publicMany([V_ID], 'owner'))->toThrow(IdentityRejected::class, 'member or anonymous');
    });

    it('reads a single vehicle, null when it is not readable', function () {
        $this->vehicles->add('01J0VEHICLE0000000000000A3', V_OTHER, ['identity' => ['make' => 'Lancia', 'model' => 'Delta']], ['autodonuts' => ['groups' => ['identity'], 'visibility' => 'public']]);

        expect(Identity::vehicleClient()->publicGet('01J0VEHICLE0000000000000A3')->label())->toBe('Lancia Delta')
            ->and(Identity::vehicleClient()->publicGet(V_ID))->toBeNull();
    });
});

describe('reading the vehicles linked to the product, without the person (AR-093)', function () {
    it('gives the groups of the link whatever the visibility, never the sensitive group', function () {
        $vehicle = Identity::vehicleClient()->linkedGet(V_ID, ['identity', 'usage', 'sensitive']);

        expect($vehicle->label())->toBe('Peugeot 205 GTI (1991)')->and($vehicle->usage()['mileage_km'])->toBe(182000)->and($vehicle->sensitive())->toBeNull();
    });

    it('reads a batch, leaving out the vehicles not linked to the product', function () {
        $this->vehicles->add('01J0VEHICLE0000000000000A3', V_OTHER, ['identity' => ['make' => 'Lancia', 'model' => 'Delta']], ['autotrackly' => ['groups' => ['identity']]]);

        $vehicles = Identity::vehicleClient()->linkedMany([V_ID, '01J0VEHICLE0000000000000A3']);

        expect(array_map(fn ($vehicle) => $vehicle->id, $vehicles))->toBe([V_ID])
            ->and(Identity::vehicleClient()->linkedGet('01J0VEHICLE0000000000000A3'))->toBeNull()
            ->and(Identity::vehicleClient()->linkedMany([]))->toBe([]);
    });
});

describe('the short cache', function () {
    it('serves a repeated read from the cache, then reads again once it has expired', function () {
        $headers = vehicleHeaders($this);

        $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();
        $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();
        expect($this->vehicles->reads())->toBe(1);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this))->assertOk();
        expect($this->vehicles->reads())->toBe(2);
    });

    it('reads again after the person wrote, so that they see their own change', function () {
        $headers = vehicleHeaders($this);
        $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();

        $this->patchJson('/api/vehicles/'.V_ID, ['version' => 1, 'data' => ['specs' => ['color' => 'Bleu']]], $headers)->assertOk();
        $after = $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();

        expect($after->json('data.groups.specs.color'))->toBe('Bleu')->and($this->vehicles->reads())->toBe(2);
    });

    it('does not share the cache between two people', function () {
        $this->vehicles->add('01J0VEHICLE0000000000000A6', V_OTHER, ['identity' => ['make' => 'Fiat', 'model' => '500']], ['autodonuts' => ['groups' => ['identity']]]);

        $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this))->assertOk();
        $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this, V_OTHER))->assertNotFound();

        expect($this->vehicles->reads())->toBe(2);
    });

    it('serves a stale copy when Identity is down, marked as such, and fails without one', function () {
        $headers = vehicleHeaders($this);
        $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();
        $this->identity->goDown();
        Carbon::setTestNow(now()->addSeconds(61));

        $stale = $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this))->assertOk();
        expect($stale->json('data.stale'))->toBeTrue()->and($stale->json('data.groups.identity.make'))->toBe('Peugeot');

        $this->withoutExceptionHandling();
        expect(fn () => $this->getJson('/api/garage', vehicleHeaders($this)))->toThrow(IdentityUnavailable::class);
    });

    it('does not serve a stale copy after the stale window', function () {
        $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this))->assertOk();
        $this->identity->goDown();
        Carbon::setTestNow(now()->addSeconds(3601));
        $this->withoutExceptionHandling();

        expect(fn () => $this->getJson('/api/vehicles/'.V_ID, vehicleHeaders($this)))->toThrow(IdentityUnavailable::class);
    });

    it('can be disabled', function () {
        config(['identity-connector.vehicles.cache_seconds' => 0]);
        app()->forgetInstance(VehicleClient::class);
        app()->forgetInstance(IdentityManager::class);
        Identity::clearResolvedInstance(IdentityManager::class);
        $headers = vehicleHeaders($this);

        $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();
        $this->getJson('/api/vehicles/'.V_ID, $headers)->assertOk();

        expect($this->vehicles->reads())->toBe(2);
    });
});

describe('events', function () {
    it('relays a deleted vehicle, and the product closes its local data', function () {
        VehicleNote::create(['identity_vehicle_id' => V_ID, 'note' => 'À vendre']);
        VehicleNote::create(['identity_vehicle_id' => '01J0VEHICLE0000000000000A2', 'note' => 'Autre']);
        ['body' => $body, 'server' => $server] = $this->identity->webhook('vehicle.deleted', V_ID);

        $this->call('POST', '/identity/webhooks', [], [], [], $server, $body)->assertNoContent();

        expect(VehicleNote::pluck('identity_vehicle_id')->all())->toBe(['01J0VEHICLE0000000000000A2']);
    });

    it('relays an unlinked vehicle with the product', function () {
        Event::fake([VehicleUnlinked::class, VehicleDeleted::class]);
        ['body' => $body, 'server' => $server] = $this->identity->webhook('vehicle.unlinked', V_ID);

        $this->call('POST', '/identity/webhooks', [], [], [], $server, $body)->assertNoContent();

        Event::assertDispatched(VehicleUnlinked::class, fn ($event) => $event->vehicleId === V_ID && $event->product === 'autodonuts');
        Event::assertNotDispatched(VehicleDeleted::class);
    });

    it('refuses a vehicle event that lacks its identifiers', function (string $type, array $data) {
        $body = json_encode(['id' => 'a', 'type' => $type, 'version' => 1, 'occurred_at' => now()->toIso8601String(), 'data' => $data]);
        $server = $this->identity->signedWebhook($body);

        $this->call('POST', '/identity/webhooks', [], [], [], $server, $body)->assertStatus(400);
    })->with([
        'deleted without vehicle' => ['vehicle.deleted', ['user_id' => V_OWNER]],
        'unlinked without product' => ['vehicle.unlinked', ['vehicle_id' => V_ID]],
        'unlinked with an empty product' => ['vehicle.unlinked', ['vehicle_id' => V_ID, 'product' => '']],
    ]);
});
