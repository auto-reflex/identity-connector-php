<?php

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\IdentityUnavailable;
use AutoReflex\IdentityConnector\Testing\FakeIdentity;
use Carbon\Carbon;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

const CLIENT_PERSON = '01J0USER00000000000000000A';

function makeClient(Factory $http, ?Repository $cache = null, array $secrets = []): IdentityClient
{
    return new IdentityClient(
        $http,
        $cache ?? new Repository(new ArrayStore),
        new Encrypter(str_repeat('k', 32), 'aes-256-cbc'),
        'https://identity.test',
        serviceClientId: 'autotrackly-api-service',
        serviceClientSecret: 'service-secret',
        exchangeSecrets: $secrets,
    );
}

function productToken(): string
{
    return test()->identity->tokenFor(CLIENT_PERSON, ['profile', 'email'], ['client_id' => 'autotrackly-mobile']);
}

function requestsTo(Factory $http, string $path): int
{
    return $http->recorded()->filter(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_PATH) === $path)->count();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = FakeIdentity::install('autotrackly-api')
        ->user(CLIENT_PERSON, name: 'Camille Durand')
        ->user('01J0OTHER0000000000000000B', name: 'Alex Martin')
        ->organization('01J0ORG0000000000000000001', 'Garage Dupont', [CLIENT_PERSON => 'owner', '01J0OTHER0000000000000000B' => 'member'])
        ->organization('01J0ORG0000000000000000002', 'Club Passion', ['01J0OTHER0000000000000000B' => 'owner', CLIENT_PERSON => 'member'])
        ->organization('01J0ORG0000000000000000003', 'Secret SARL', ['01J0OTHER0000000000000000B' => 'owner'])
        ->serviceClient('autotrackly-api-service', 'service-secret');
    $this->http = Http::getFacadeRoot();
    $this->client = makeClient($this->http);
});

afterEach(fn () => Carbon::setTestNow());

describe('organizations', function () {
    it('lists the organizations of the person, exchanging the product token for an identity-api token', function () {
        $organizations = $this->client->organizations(productToken());

        expect($organizations)->toHaveCount(2)
            ->and($organizations[0]->name)->toBe('Club Passion')->and($organizations[0]->role)->toBe('member')->and($organizations[0]->members)->toBeNull()
            ->and($organizations[1]->name)->toBe('Garage Dupont')->and($organizations[1]->role)->toBe('owner');
    });

    it('reads the detail with the members', function () {
        $organization = $this->client->organization(productToken(), '01J0ORG0000000000000000001');

        expect($organization->role)->toBe('owner')
            ->and($organization->members)->toHaveCount(2)
            ->and($organization->members[1]->name)->toBe('Alex Martin')->and($organization->members[1]->role)->toBe('member');
    });

    it('answers null for an organization the person cannot see, whether it exists or not', function () {
        expect($this->client->organization(productToken(), '01J0ORG0000000000000000003'))->toBeNull()
            ->and($this->client->organization(productToken(), 'nope'))->toBeNull();
    });

    it('exchanges once and reuses the identity-api token until shortly before it expires', function () {
        $token = productToken();

        $this->client->organizations($token);
        $this->client->organizations($token);
        $this->client->organization($token, '01J0ORG0000000000000000001');
        expect($this->identity->exchangeCalls())->toBe(1);

        Carbon::setTestNow(now()->addSeconds(869));
        $this->client->organizations($token);
        expect($this->identity->exchangeCalls())->toBe(1);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->client->organizations($token);
        expect($this->identity->exchangeCalls())->toBe(2);
    });

    it('does not share an exchanged token between two people', function () {
        $this->client->organizations(productToken());
        $other = $this->identity->tokenFor('01J0OTHER0000000000000000B', ['profile'], ['client_id' => 'autotrackly-mobile']);

        $organizations = $this->client->organizations($other);

        expect($this->identity->exchangeCalls())->toBe(2)
            ->and(collect($organizations)->pluck('name')->all())->toContain('Secret SARL');
    });

    it('stores the exchanged token encrypted, never in clear', function () {
        $cache = new Repository(new ArrayStore);
        $client = makeClient($this->http, $cache);

        $client->organizations(productToken());

        $stored = collect((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($cache->getStore()))->pluck('value');
        expect($stored)->toHaveCount(1)->and($stored[0])->not->toContain('.');
    });

    it('sends the secret of a confidential client at exchange time', function () {
        $http = new Factory;
        $http->fake(['*' => fn (Request $request) => $request->url() === 'https://identity.test/oauth/token'
            ? Factory::response(['access_token' => 'identity-token', 'expires_in' => 900])
            : Factory::response(['data' => []])]);
        $client = makeClient($http, secrets: ['autoreflex-map-web' => 'map-secret']);

        $client->organizations($this->identity->tokenFor(CLIENT_PERSON, claims: ['client_id' => 'autoreflex-map-web']));

        $exchange = $http->recorded()->first()[0];
        expect($exchange->data())->toMatchArray(['client_id' => 'autoreflex-map-web', 'client_secret' => 'map-secret', 'audience' => 'identity-api', 'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange']);
    });

    it('exchanges again, once, when the cached identity-api token was revoked meanwhile', function () {
        $http = new Factory;
        $http->fake(['https://identity.test/oauth/token' => $http->sequence()
            ->push(['access_token' => 'stale', 'expires_in' => 900])
            ->push(['access_token' => 'fresh', 'expires_in' => 900]),
            'https://identity.test/api/v1/organizations' => fn (Request $request) => $request->header('Authorization')[0] === 'Bearer fresh'
                ? Factory::response(['data' => []])
                : Factory::response(['error' => 'invalid_token'], 401),
        ]);
        $client = makeClient($http);

        expect($client->organizations(productToken()))->toBe([])
            ->and(requestsTo($http, '/oauth/token'))->toBe(2);
    });

    it('gives up when the second exchange is also refused', function () {
        $http = new Factory;
        $http->fake([
            'https://identity.test/oauth/token' => Factory::response(['access_token' => 'nope', 'expires_in' => 900]),
            'https://identity.test/api/v1/organizations' => Factory::response(['error' => 'invalid_token'], 401),
        ]);

        expect(fn () => makeClient($http)->organizations(productToken()))->toThrow(IdentityRejected::class);
        expect(requestsTo($http, '/oauth/token'))->toBe(2);
    });

    it('is rejected when Identity refuses the exchange (revoked, suspended, wrong client)', function () {
        $this->identity->revoke(CLIENT_PERSON);

        expect(fn () => $this->client->organizations(productToken()))->toThrow(function (IdentityRejected $e) {
            expect($e->status)->toBe(400)->and($e->error)->toBe('invalid_grant');
        });
    });

    it('refuses a product token without a readable client_id', function () {
        expect(fn () => $this->client->organizations('not-a-jwt'))->toThrow(IdentityRejected::class);
    });
});

describe('account status', function () {
    it('reads the status of accounts with a service token', function () {
        $this->identity->revoke('01J0OTHER0000000000000000B');

        $active = $this->client->accountStatus(CLIENT_PERSON);
        $suspended = $this->client->accountStatus('01J0OTHER0000000000000000B');
        $unknown = $this->client->accountStatus('01J0NOBODY000000000000000Z');

        expect($active->isActive())->toBeTrue()
            ->and($suspended->exists)->toBeTrue()->and($suspended->suspended)->toBeTrue()->and($suspended->isActive())->toBeFalse()
            ->and($unknown->exists)->toBeFalse()->and($unknown->isActive())->toBeFalse();
    });

    it('obtains the service token once and reuses it', function () {
        $this->client->accountStatus(CLIENT_PERSON);
        $this->client->accountStatus(CLIENT_PERSON);

        expect($this->identity->serviceTokenCalls())->toBe(1);

        Carbon::setTestNow(now()->addSeconds(871));
        $this->client->accountStatus(CLIENT_PERSON);
        expect($this->identity->serviceTokenCalls())->toBe(2);
    });

    it('fails clearly without a configured service client, and with a wrong secret', function () {
        $unconfigured = new IdentityClient($this->http, new Repository(new ArrayStore), new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), 'https://identity.test');
        $wrong = new IdentityClient($this->http, new Repository(new ArrayStore), new Encrypter(str_repeat('k', 32), 'aes-256-cbc'), 'https://identity.test', serviceClientId: 'autotrackly-api-service', serviceClientSecret: 'wrong');

        expect(fn () => $unconfigured->accountStatus(CLIENT_PERSON))->toThrow(IdentityRejected::class, 'not configured')
            ->and(fn () => $wrong->accountStatus(CLIENT_PERSON))->toThrow(IdentityRejected::class);
    });
});

describe('failures', function () {
    it('retries a read once on a server error, then reports Identity unavailable', function () {
        $http = new Factory;
        $http->fake(['*' => Factory::response('', 503)]);

        expect(fn () => makeClient($http)->userInfo('token'))->toThrow(IdentityUnavailable::class);
        expect(requestsTo($http, '/userinfo'))->toBe(2);
    });

    it('recovers when the retry succeeds', function () {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->pushStatus(502)->push(['sub' => CLIENT_PERSON, 'name' => 'Camille'])]);

        expect(makeClient($http)->userInfo('token')->id)->toBe(CLIENT_PERSON);
    });

    it('retries a read once on a connection failure', function () {
        $http = new Factory;
        $http->fake(['*' => $http->sequence()->pushFailedConnection()->push(['sub' => CLIENT_PERSON])]);

        expect(makeClient($http)->userInfo('token')->id)->toBe(CLIENT_PERSON);
    });

    it('reports Identity unavailable on repeated connection failures and on throttling', function () {
        $http = new Factory;
        $http->fake(fn () => throw new ConnectionException('boom'));
        expect(fn () => makeClient($http)->userInfo('token'))->toThrow(IdentityUnavailable::class);

        $throttled = new Factory;
        $throttled->fake(['*' => Factory::response('', 429)]);
        expect(fn () => makeClient($throttled)->userInfo('token'))->toThrow(IdentityUnavailable::class);
    });

    it('does not retry a refusal', function () {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['error' => 'invalid_token'], 401)]);

        expect(fn () => makeClient($http)->userInfo('token'))->toThrow(function (IdentityRejected $e) {
            expect($e->status)->toBe(401)->and($e->error)->toBe('invalid_token');
        });
        expect(requestsTo($http, '/userinfo'))->toBe(1);
    });

    it('does not retry a token request (POST)', function () {
        $http = new Factory;
        $http->fake(['*' => Factory::response('', 503)]);

        expect(fn () => makeClient($http)->accountStatus(CLIENT_PERSON))->toThrow(IdentityUnavailable::class);
        expect(requestsTo($http, '/oauth/token'))->toBe(1);
    });

    it('treats an unusable answer as Identity being unavailable', function () {
        $http = new Factory;
        $http->fake(['*' => Factory::response('<html>proxy error</html>', 200)]);

        expect(fn () => makeClient($http)->userInfo('token'))->toThrow(IdentityUnavailable::class);
    });

    it('never puts a token in an exception message', function () {
        $http = new Factory;
        $http->fake(['*' => Factory::response(['error' => 'invalid_token'], 401)]);

        try {
            makeClient($http)->userInfo('super-secret-token');
        } catch (IdentityRejected $e) {
            expect($e->getMessage())->not->toContain('super-secret-token');
        }
    });

    it('does not follow redirects', function () {
        $http = new Factory;
        $http->fake(['*' => Factory::response('', 302, ['Location' => 'https://evil.test/userinfo'])]);

        expect(fn () => makeClient($http)->userInfo('token'))->toThrow(IdentityRejected::class);
        expect($http->recorded())->toHaveCount(1);
    });
});
