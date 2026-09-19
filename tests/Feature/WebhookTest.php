<?php

use AutoReflex\IdentityConnector\Events\AccountReinstated;
use AutoReflex\IdentityConnector\Events\AccountSuspended;
use AutoReflex\IdentityConnector\Webhooks\WebhookSignature;
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

    it('a signature made with another secret', function () {
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);
        $server['HTTP_IDENTITY_SIGNATURE'] = WebhookSignature::header($body, 'attacker-secret-0123456789abcdefghij', Carbon::now()->getTimestamp());

        postWebhook($this, $body, $server)->assertUnauthorized();
    });

    it('a missing header', function () {
        ['body' => $body] = $this->identity->webhook('account.suspended', WH_USER);

        postWebhook($this, $body, ['CONTENT_TYPE' => 'application/json'])->assertUnauthorized();
    });

    it('a stale timestamp: a captured webhook cannot be replayed later', function () {
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, timestamp: Carbon::now()->getTimestamp() - 301);

        postWebhook($this, $body, $server)->assertUnauthorized();
    });

    it('a timestamp too far in the future', function () {
        ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER, timestamp: Carbon::now()->getTimestamp() + 301);

        postWebhook($this, $body, $server)->assertUnauthorized();
    });
});

it('gives nothing away in the refusal', function () {
    ['body' => $body] = $this->identity->webhook('account.suspended', WH_USER);

    expect(postWebhook($this, $body, ['CONTENT_TYPE' => 'application/json'])->json())->toBe(['error' => 'invalid_signature']);
});

it('accepts the previous secret during a rotation, and refuses it once removed', function () {
    config(['identity-connector.webhooks.secrets' => ['new-secret-0123456789abcdefghijklmnopq', 'test-webhook-secret-0123456789abcdef0123']]);
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);
    postWebhook($this, $body, $server)->assertNoContent();

    config(['identity-connector.webhooks.secrets' => ['new-secret-0123456789abcdefghijklmnopq']]);
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);
    postWebhook($this, $body, $server)->assertUnauthorized();
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

    postWebhook($this, $body, $server)->assertStatus(500);

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
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDENTITY_SIGNATURE' => WebhookSignature::header($body, 'test-webhook-secret-0123456789abcdef0123', now()->getTimestamp())];

        postWebhook($this, $body, $server)->assertStatus(202);

        Event::assertNotDispatched(AccountSuspended::class);
        Log::shouldHaveReceived('warning')->once();
    });
});

describe('refuses with 400 a signed but malformed event', function () {
    it('whatever is wrong', function (string $body) {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDENTITY_SIGNATURE' => WebhookSignature::header($body, 'test-webhook-secret-0123456789abcdef0123', now()->getTimestamp())];

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
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDENTITY_SIGNATURE' => WebhookSignature::header($body, 'test-webhook-secret-0123456789abcdef0123', now()->getTimestamp())];

    postWebhook($this, $body, $server)->assertStatus(413);
});

it('is served on a route without session or CSRF, and only by POST', function () {
    $this->get('/identity/webhooks')->assertStatus(405);
    expect(route('identity-connector.webhooks', absolute: false))->toBe('/identity/webhooks');
});

it('fails closed while no secret is configured', function () {
    config(['identity-connector.webhooks.secrets' => []]);
    ['body' => $body, 'server' => $server] = $this->identity->webhook('account.suspended', WH_USER);

    postWebhook($this, $body, $server)->assertUnauthorized();
});
