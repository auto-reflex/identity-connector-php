<?php

use AutoGteck\IdentityConnector\Jwt\KeySetUnavailable;
use AutoGteck\IdentityConnector\Jwt\RemoteKeySet;
use AutoGteck\IdentityConnector\Testing\SigningKey;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;

const JWKS_URL = 'https://identity.test/.well-known/jwks.json';

/**
 * @param  list<SigningKey>  $keys
 */
function jwks(array $keys, ?string $cacheControl = 'public, max-age=300'): PromiseInterface
{
    return Factory::response(['keys' => array_map(fn (SigningKey $key) => $key->jwk(), $keys)], 200, $cacheControl === null ? [] : ['Cache-Control' => $cacheControl]);
}

function fetches(Factory $http): int
{
    return $http->recorded()->filter(fn (array $pair) => $pair[0] instanceof Request && $pair[0]->url() === JWKS_URL)->count();
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->http = new Factory;
    $this->cache = new Repository(new ArrayStore);
    $this->keySet = new RemoteKeySet($this->cache, $this->http, JWKS_URL);
    $this->a = SigningKey::generate('key-a');
    $this->b = SigningKey::generate('key-b');
});

afterEach(fn () => Carbon::setTestNow());

it('fetches the key set once and serves it from the cache', function () {
    $this->http->fake([JWKS_URL => jwks([$this->a])]);

    expect($this->keySet->key('key-a'))->not->toBeNull()
        ->and($this->keySet->key('key-a'))->not->toBeNull();

    expect(fetches($this->http))->toBe(1);
});

it('returns null for a kid that Identity does not publish', function () {
    $this->http->fake([JWKS_URL => jwks([$this->a])]);

    expect($this->keySet->key('nope'))->toBeNull();
});

it('reloads once the response max-age has elapsed', function () {
    $this->http->fake([JWKS_URL => jwks([$this->a], 'public, max-age=120')]);

    $this->keySet->key('key-a');
    Carbon::setTestNow(now()->addSeconds(119));
    $this->keySet->key('key-a');
    expect(fetches($this->http))->toBe(1);

    Carbon::setTestNow(now()->addSeconds(2));
    $this->keySet->key('key-a');
    expect(fetches($this->http))->toBe(2);
});

it('defaults to 300 s without Cache-Control and clamps absurd values', function (?string $header, int $seconds) {
    $this->http->fake([JWKS_URL => jwks([$this->a], $header)]);

    $this->keySet->key('key-a');
    Carbon::setTestNow(now()->addSeconds($seconds - 1));
    $this->keySet->key('key-a');
    expect(fetches($this->http))->toBe(1);

    Carbon::setTestNow(now()->addSeconds(2));
    $this->keySet->key('key-a');
    expect(fetches($this->http))->toBe(2);
})->with([
    'no header' => [null, 300],
    'max-age=0 is clamped to 30' => ['max-age=0', 30],
    'huge max-age is clamped to 1 h' => ['max-age=99999999', 3600],
]);

it('picks up a rotated key when it meets an unknown kid, without waiting for the expiry', function () {
    $this->http->fake([JWKS_URL => $this->http->sequence()->pushResponse(jwks([$this->a]))->pushResponse(jwks([$this->a, $this->b]))]);

    $this->keySet->key('key-a');
    Carbon::setTestNow(now()->addSeconds(61));

    expect($this->keySet->key('key-b'))->not->toBeNull()
        ->and(fetches($this->http))->toBe(2);
});

it('reloads at most once per minute for unknown kids', function () {
    $this->http->fake([JWKS_URL => jwks([$this->a])]);

    $this->keySet->key('key-a');

    foreach (range(1, 50) as $i) {
        expect($this->keySet->key("forged-{$i}"))->toBeNull();
    }
    expect(fetches($this->http))->toBe(1);

    Carbon::setTestNow(now()->addSeconds(61));
    $this->keySet->key('forged-again');
    $this->keySet->key('forged-again-2');
    expect(fetches($this->http))->toBe(2);
});

it('does not fetch twice on a cold cache with an unknown kid', function () {
    $this->http->fake([JWKS_URL => jwks([$this->a])]);

    expect($this->keySet->key('unknown'))->toBeNull();

    expect(fetches($this->http))->toBe(1);
});

describe('when Identity is unreachable', function () {
    it('throws when there is nothing in the cache', function () {
        $this->http->fake([JWKS_URL => Factory::response('', 503)]);

        expect(fn () => $this->keySet->key('key-a'))->toThrow(KeySetUnavailable::class);
    });

    it('throws on a network failure with an empty cache', function () {
        $this->http->fake(fn () => throw new ConnectionException('boom'));

        expect(fn () => $this->keySet->key('key-a'))->toThrow(KeySetUnavailable::class);
    });

    it('keeps serving the last known keys, without hammering Identity', function () {
        $this->http->fake([JWKS_URL => $this->http->sequence()->pushResponse(jwks([$this->a]))->pushStatus(503)->pushStatus(503)->pushStatus(503)]);
        $this->keySet->key('key-a');

        Carbon::setTestNow(now()->addSeconds(301));
        expect($this->keySet->key('key-a'))->not->toBeNull();
        $afterFirstFailure = fetches($this->http);

        foreach (range(1, 20) as $i) {
            expect($this->keySet->key('key-a'))->not->toBeNull();
        }
        expect(fetches($this->http))->toBe($afterFirstFailure);

        Carbon::setTestNow(now()->addSeconds(31));
        expect($this->keySet->key('key-a'))->not->toBeNull()
            ->and(fetches($this->http))->toBe($afterFirstFailure + 1);
    });

    it('recovers as soon as Identity answers again', function () {
        $this->http->fake([JWKS_URL => $this->http->sequence()->pushResponse(jwks([$this->a]))->pushStatus(503)->pushResponse(jwks([$this->a, $this->b]))]);
        $this->keySet->key('key-a');

        Carbon::setTestNow(now()->addSeconds(301));
        $this->keySet->key('key-a');
        Carbon::setTestNow(now()->addSeconds(31));

        expect($this->keySet->key('key-b'))->not->toBeNull();
    });

    it('gives up after 24 hours without a fresh key set', function () {
        $this->http->fake([JWKS_URL => $this->http->sequence()->pushResponse(jwks([$this->a]))->whenEmpty(Factory::response('', 503))]);
        $this->keySet->key('key-a');

        Carbon::setTestNow(now()->addHours(23));
        expect($this->keySet->key('key-a'))->not->toBeNull();

        Carbon::setTestNow(now()->addHours(2));
        expect(fn () => $this->keySet->key('key-a'))->toThrow(KeySetUnavailable::class);
    });
});

describe('ignores what Identity should not publish', function () {
    it('non-RSA keys, keys without kid, other algorithms and encryption keys', function () {
        $good = $this->a->jwk();
        $this->http->fake([JWKS_URL => Factory::response(['keys' => [
            ['kty' => 'oct', 'kid' => 'sym', 'k' => 'c2VjcmV0'],
            ['kty' => 'RSA', 'n' => $good['n'], 'e' => $good['e']],
            [...$good, 'kid' => 'hs', 'alg' => 'HS256'],
            [...$good, 'kid' => 'enc', 'use' => 'enc'],
            'garbage',
            $good,
        ]])]);

        expect($this->keySet->key('key-a'))->not->toBeNull()
            ->and($this->keySet->key('sym'))->toBeNull()
            ->and($this->keySet->key('hs'))->toBeNull()
            ->and($this->keySet->key('enc'))->toBeNull();
    });

    it('a key set without any usable key, and does not let it replace a good cache', function () {
        $this->http->fake([JWKS_URL => $this->http->sequence()->pushResponse(jwks([$this->a]))->push(['keys' => []])]);
        $this->keySet->key('key-a');

        Carbon::setTestNow(now()->addSeconds(301));

        expect($this->keySet->key('key-a'))->not->toBeNull();
    });

    it('a response that is not JSON, redirects and oversized bodies', function (Response|PromiseInterface $response) {
        $this->http->fake([JWKS_URL => $response]);

        expect(fn () => $this->keySet->key('key-a'))->toThrow(KeySetUnavailable::class);
    })->with([
        'html' => fn () => Factory::response('<html></html>', 200),
        'redirect' => fn () => Factory::response('', 302, ['Location' => 'https://evil.test/jwks']),
        'oversized' => fn () => Factory::response(json_encode(['keys' => [], 'padding' => str_repeat('x', 70000)]), 200),
    ]);
});
