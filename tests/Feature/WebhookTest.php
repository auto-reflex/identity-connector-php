<?php

use AutoGteck\IdentityConnector\Events\AccountReinstated;
use AutoGteck\IdentityConnector\Events\AccountSuspended;
use AutoGteck\IdentityConnector\Testing\SigningKey;
use AutoGteck\IdentityConnector\Webhooks\WebhookSignature;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Workbench\App\Models\Profile;

const WH_USER = '01J0USER00000000000000000A';

/**
 * @param  array<string, string>  $server
 */
function postWebhook(TestCase $test, string $body, array $server): TestResponse
{
    return $test->call('POST', '/identity/webhooks', [], [], [], $server, $body);
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autotrackly-api')->user(WH_USER);
});

afterEach(fn () => Carbon::setTestNow());

it('turns a valid signed webhook into a local event', function () {
    Event::fake([AccountSuspended::class, AccountReinstated::class]);
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, '01J0EVENT0000000000000000A');

    postWebhook($this, $body, $server)->assertNoContent();

    Event::assertDispatched(AccountSuspended::class, fn ($event) => $event->userId === WH_USER && $event->eventId === '01J0EVENT0000000000000000A'
        && $event->occurredAt->equalTo(Carbon::now('UTC')));
    Event::assertNotDispatched(AccountReinstated::class);
});

it('dispatches account.reinstated', function () {
    Event::fake([AccountSuspended::class, AccountReinstated::class]);
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.reinstated', WH_USER);

    postWebhook($this, $body, $server)->assertNoContent();

    Event::assertDispatched(AccountReinstated::class, fn ($event) => $event->userId === WH_USER);
});

it('suspends and reinstates a product profile end to end, immediately', function () {
    $token = fn () => ['Authorization' => 'Bearer '.$this->identity->tokenFor(WH_USER)];
    $this->getJson('/api/me', $token())->assertOk();

    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);
    postWebhook($this, $body, $server)->assertNoContent();
    $this->getJson('/api/me', $token())->assertForbidden()->assertJson(['error' => 'profile_suspended']);

    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.reinstated', WH_USER);
    postWebhook($this, $body, $server)->assertNoContent();
    $this->getJson('/api/me', $token())->assertOk();
});

it('does not lift a local suspension when Identity reinstates the account', function () {
    $this->getJson('/api/me', ['Authorization' => 'Bearer '.$this->identity->tokenFor(WH_USER)])->assertOk();
    Profile::query()->update(['product_suspended_at' => now()]);

    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.reinstated', WH_USER);
    postWebhook($this, $body, $server)->assertNoContent();

    expect(Profile::first()->isSuspended())->toBeTrue();
});

it('ignores a webhook about someone who has no profile here', function () {
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', '01J0NOBODY000000000000000Z');

    postWebhook($this, $body, $server)->assertNoContent();

    expect(Profile::count())->toBe(0);
});

describe('refuses with 401', function () {
    it('a body altered after signing', function () {
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);

        postWebhook($this, str_replace(WH_USER, '01J0OTHER0000000000000000B', $body), $server)->assertUnauthorized();
    });

    it('a token signed by a key Identity does not publish', function () {
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);
        $now = Carbon::now()->getTimestamp();
        $server['HTTP_IDENTITY_SIGNATURE'] = SigningKey::generate('test-key-1')->sign(
            ['iss' => 'https://identity.test', 'aud' => 'autotrackly-api', 'iat' => $now, 'exp' => $now + 300, 'body' => WebhookSignature::digest($body)],
            ['typ' => WebhookSignature::TYPE],
        );

        postWebhook($this, $body, $server)->assertUnauthorized();
    });

    it('a webhook meant for another product', function () {
        $body = $this->identity->webhook('account.suspended', WH_USER)['body'];

        postWebhook($this, $body, $this->identity->signedWebhook($body, claims: ['aud' => 'autodonuts-api']))->assertUnauthorized();
    });

    it('another issuer', function () {
        $body = $this->identity->webhook('account.suspended', WH_USER)['body'];

        postWebhook($this, $body, $this->identity->signedWebhook($body, claims: ['iss' => 'https://evil.test']))->assertUnauthorized();
    });

    it('an access token presented as a signature', function () {
        $body = $this->identity->webhook('account.suspended', WH_USER)['body'];
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDENTITY_SIGNATURE' => $this->identity->tokenFor(WH_USER, claims: ['body' => WebhookSignature::digest($body)])];

        postWebhook($this, $body, $server)->assertUnauthorized();
    });

    it('a missing header', function () {
        ['body' => $body] = $this->identity->webhook('account.suspended', WH_USER);

        postWebhook($this, $body, ['CONTENT_TYPE' => 'application/json'])->assertUnauthorized();
    });

    it('an expired token, beyond the one-minute clock tolerance: a captured webhook cannot be replayed later', function () {
        // Signé il y a 6 min 1 s : expiré depuis 61 s (5 min de validité, AR-089).
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, timestamp: Carbon::now()->getTimestamp() - 361);

        postWebhook($this, $body, $server)->assertUnauthorized();
    });

    it('a timestamp too far in the future', function () {
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, timestamp: Carbon::now()->getTimestamp() + 301);

        postWebhook($this, $body, $server)->assertUnauthorized();
    });
});

it('accepts a token that expired less than a minute ago (clock tolerance)', function () {
    // Signé il y a 5 min 30 s : expiré depuis 30 s, dans la tolérance d'une minute.
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, timestamp: Carbon::now()->getTimestamp() - 330);

    postWebhook($this, $body, $server)->assertNoContent();
});

it('gives nothing away in the refusal', function () {
    ['body' => $body] = $this->identity->webhook('account.suspended', WH_USER);

    expect(postWebhook($this, $body, ['CONTENT_TYPE' => 'application/json'])->json())->toBe(['error' => 'invalid_signature']);
});

it('accepts a webhook signed just before a key rotation, and one signed by the new key', function () {
    ['body' => $before, 'server' => $signedBefore] = $this->identity->webhook('account.suspended', WH_USER);
    $this->identity->rotateKey();
    ['body' => $after, 'server' => $signedAfter] = $this->identity->webhook('account.reinstated', WH_USER);

    postWebhook($this, $before, $signedBefore)->assertNoContent();
    postWebhook($this, $after, $signedAfter)->assertNoContent();
});

it('answers 503 when the keys of Identity cannot be fetched, so that Identity delivers again', function () {
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);
    $this->identity->goDown();

    postWebhook($this, $body, $server)->assertStatus(503);
});

it('processes an event once: a redelivery is acknowledged, not replayed', function () {
    Event::fake([AccountSuspended::class]);
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, '01J0EVENT0000000000000000A');

    postWebhook($this, $body, $server)->assertNoContent();
    postWebhook($this, $body, $server)->assertOk()->assertJson(['status' => 'duplicate']);

    Event::assertDispatchedTimes(AccountSuspended::class, 1);
});

it('forgets an event whose processing failed, so that Identity can redeliver it', function () {
    $fail = true;
    Event::listen(AccountSuspended::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('database down');
        }
    });
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, '01J0EVENT0000000000000000A');

    postWebhook($this, $body, $server)->assertServerError();

    $fail = false;
    $second = $this->identity->webhook('account.suspended', WH_USER, '01J0EVENT0000000000000000A');
    postWebhook($this, $second['body'], $second['server'])->assertNoContent();
});

describe('forward compatibility', function () {
    it('acknowledges and ignores an event type it does not know', function () {
        Event::fake([AccountSuspended::class, AccountReinstated::class]);
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.something_new', WH_USER);

        postWebhook($this, $body, $server)->assertStatus(202);

        Event::assertNothingDispatched();
    });

    it('acknowledges but does not apply a version it does not know, and logs it', function () {
        Event::fake([AccountSuspended::class]);
        Log::spy();
        $body = json_encode(['id' => '01J0EVENT0000000000000000A', 'type' => 'account.suspended', 'version' => 2, 'occurred_at' => now()->toIso8601String(), 'data' => ['user_id' => WH_USER]]);
        $server = $this->identity->signedWebhook($body);

        postWebhook($this, $body, $server)->assertStatus(202);

        Event::assertNotDispatched(AccountSuspended::class);
        Log::shouldHaveReceived('warning')->once();
    });
});

describe('refuses with 400 a signed but malformed event', function () {
    it('whatever is wrong', function (string $body) {
        $server = $this->identity->signedWebhook($body);

        postWebhook($this, $body, $server)->assertStatus(400);
    })->with([
        'not json' => ['not json'],
        'a list' => ['[1,2]'],
        'no id' => ['{"type":"account.suspended","version":1,"occurred_at":"2026-09-20T10:00:00+00:00","data":{"user_id":"x"}}'],
        'no user' => ['{"id":"a","type":"account.suspended","version":1,"occurred_at":"2026-09-20T10:00:00+00:00","data":{}}'],
        'empty user' => ['{"id":"a","type":"account.suspended","version":1,"occurred_at":"2026-09-20T10:00:00+00:00","data":{"user_id":""}}'],
        'numeric user' => ['{"id":"a","type":"account.suspended","version":1,"occurred_at":"2026-09-20T10:00:00+00:00","data":{"user_id":12}}'],
        'string version' => ['{"id":"a","type":"account.suspended","version":"1","occurred_at":"2026-09-20T10:00:00+00:00","data":{"user_id":"x"}}'],
    ]);
});

it('refuses an oversized body before reading it', function () {
    $body = json_encode(['padding' => str_repeat('x', 20000)]);
    $server = $this->identity->signedWebhook($body);

    postWebhook($this, $body, $server)->assertStatus(413);
});

it('is served on a route without session or CSRF, and only by POST', function () {
    $this->get('/identity/webhooks')->assertStatus(405);
    expect(route('identity-connector.webhooks', absolute: false))->toBe('/identity/webhooks');
});
