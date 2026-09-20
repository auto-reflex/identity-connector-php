<?php

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\IdentityUnavailable;
use AutoReflex\IdentityConnector\Events\AccountDeletionCancelled;
use AutoReflex\IdentityConnector\Events\AccountDeletionDue;
use AutoReflex\IdentityConnector\Events\AccountDeletionRequested;
use AutoReflex\IdentityConnector\Events\OrganizationDeleted;
use AutoReflex\IdentityConnector\Facades\Identity;
use AutoReflex\IdentityConnector\IdentityManager;
use AutoReflex\IdentityConnector\Webhooks\WebhookSignature;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Workbench\App\Models\Profile;

const DEL_USER = '01J0USER00000000000000000A';

function deliver(TestCase $test, array $webhook): TestResponse
{
    return $test->call('POST', '/identity/webhooks', [], [], [], $webhook['server'], $webhook['body']);
}

function token(TestCase $test): array
{
    return ['Authorization' => 'Bearer '.$test->identity->tokenFor(DEL_USER, ['profile', 'email'], ['client_id' => 'autodonuts-mobile'])];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autodonuts-api')
        ->user(DEL_USER)
        ->serviceClient('autodonuts-api-service', 'service-secret');
    config([
        'identity-connector.service.client_id' => 'autodonuts-api-service',
        'identity-connector.service.client_secret' => 'service-secret',
    ]);
});

afterEach(fn () => Carbon::setTestNow());

describe('events', function () {
    it('relays a deletion request with its scheduled date', function () {
        Event::fake([AccountDeletionRequested::class]);
        $webhook = $this->identity->webhook('account.deletion_requested', DEL_USER, '01J0EVENT0000000000000000A', data: ['user_id' => DEL_USER, 'scheduled_for' => '2026-10-20T10:00:00+00:00']);

        deliver($this, $webhook)->assertNoContent();

        Event::assertDispatched(AccountDeletionRequested::class, fn ($event) => $event->userId === DEL_USER
            && $event->eventId === '01J0EVENT0000000000000000A'
            && $event->scheduledFor->equalTo(Carbon::parse('2026-10-20T10:00:00+00:00')));
    });

    it('relays a cancellation, a due date and a deleted organization', function () {
        Event::fake([AccountDeletionCancelled::class, AccountDeletionDue::class, OrganizationDeleted::class]);

        deliver($this, $this->identity->webhook('account.deletion_cancelled', DEL_USER))->assertNoContent();
        deliver($this, $this->identity->webhook('account.deletion_due', DEL_USER))->assertNoContent();
        deliver($this, $this->identity->webhook('organization.deleted', '01J0ORG0000000000000000001'))->assertNoContent();

        Event::assertDispatched(AccountDeletionCancelled::class, fn ($event) => $event->userId === DEL_USER);
        Event::assertDispatched(AccountDeletionDue::class, fn ($event) => $event->userId === DEL_USER);
        Event::assertDispatched(OrganizationDeleted::class, fn ($event) => $event->organizationId === '01J0ORG0000000000000000001');
    });

    it('refuses a deletion request without a date, and an organization event without organization', function () {
        $body = fn (array $envelope) => json_encode(['id' => 'a', 'version' => 1, 'occurred_at' => now()->toIso8601String(), ...$envelope]);
        $post = function (string $body) {
            $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_IDENTITY_SIGNATURE' => WebhookSignature::header($body, 'test-webhook-secret-0123456789abcdef0123', now()->getTimestamp())];

            return $this->call('POST', '/identity/webhooks', [], [], [], $server, $body);
        };

        $post($body(['type' => 'account.deletion_requested', 'data' => ['user_id' => DEL_USER]]))->assertStatus(400);
        $post($body(['type' => 'organization.deleted', 'data' => ['user_id' => DEL_USER]]))->assertStatus(400);
    });

    it('processes a redelivered deletion order once', function () {
        Event::fake([AccountDeletionDue::class]);
        $webhook = $this->identity->webhook('account.deletion_due', DEL_USER, '01J0EVENT0000000000000000B');

        deliver($this, $webhook)->assertNoContent();
        deliver($this, $webhook)->assertOk()->assertJson(['status' => 'duplicate']);

        Event::assertDispatchedTimes(AccountDeletionDue::class, 1);
    });
});

describe('the product side, end to end (workbench)', function () {
    it('locks the profile when a deletion is requested, and unlocks it when it is cancelled', function () {
        $this->getJson('/api/me', token($this))->assertOk();

        deliver($this, $this->identity->webhook('account.deletion_requested', DEL_USER))->assertNoContent();
        $this->getJson('/api/me', token($this))->assertForbidden()->assertJson(['error' => 'profile_suspended']);
        expect(Profile::first()->identity_deletion_at)->not->toBeNull();

        deliver($this, $this->identity->webhook('account.deletion_cancelled', DEL_USER))->assertNoContent();
        $this->getJson('/api/me', token($this))->assertOk();
    });

    it('erases the profile when the deletion is due, then acknowledges to Identity', function () {
        $this->getJson('/api/me', token($this))->assertOk();
        $this->identity->deletion(DEL_USER, 'processing');

        deliver($this, $this->identity->webhook('account.deletion_due', DEL_USER))->assertNoContent();

        expect(Profile::count())->toBe(0)->and($this->identity->acknowledgedDeletions())->toBe([DEL_USER]);
    });

    it('answers 500 when the acknowledgement fails, so that Identity redelivers, and can then complete', function () {
        $this->getJson('/api/me', token($this))->assertOk();
        $this->identity->deletion(DEL_USER, 'processing');
        $webhook = $this->identity->webhook('account.deletion_due', DEL_USER, '01J0EVENT0000000000000000C');
        $this->identity->goDown();

        deliver($this, $webhook)->assertServerError();
        expect(Profile::count())->toBe(0)->and($this->identity->acknowledgedDeletions())->toBe([]);

        $this->identity->comeBack();
        deliver($this, $webhook)->assertNoContent();
        expect($this->identity->acknowledgedDeletions())->toBe([DEL_USER]);
    });
});

describe('acknowledgeDeletion', function () {
    it('acknowledges with the service token, and again without harm', function () {
        $this->identity->deletion(DEL_USER, 'processing');

        expect(Identity::client()->acknowledgeDeletion(DEL_USER))->toBeTrue()
            ->and(Identity::client()->acknowledgeDeletion(DEL_USER))->toBeTrue()
            ->and($this->identity->serviceTokenCalls())->toBe(1);
    });

    it('answers false when Identity has no erasure under way for this account', function () {
        expect(Identity::client()->acknowledgeDeletion(DEL_USER))->toBeFalse();

        $this->identity->deletion(DEL_USER, 'pending');
        expect(Identity::client()->acknowledgeDeletion(DEL_USER))->toBeFalse();
    });

    it('fails with a typed error when Identity is down or refuses', function () {
        $this->identity->deletion(DEL_USER, 'processing')->goDown();
        expect(fn () => Identity::client()->acknowledgeDeletion(DEL_USER))->toThrow(IdentityUnavailable::class);

        $this->identity->comeBack();
        config(['identity-connector.service.client_secret' => 'wrong']);
        app()->forgetInstance(IdentityClient::class);
        app()->forgetInstance(IdentityManager::class);
        Identity::clearResolvedInstance(IdentityManager::class);
        expect(fn () => Identity::client()->acknowledgeDeletion(DEL_USER))->toThrow(IdentityRejected::class);
    });
});

describe('accountStatus', function () {
    it('exposes the deletion state and date, and no longer calls the account active', function () {
        $this->identity->deletion(DEL_USER, 'pending', '2026-10-20T10:00:00+00:00');

        $status = Identity::client()->accountStatus(DEL_USER);

        expect($status->deletion)->toBe('pending')
            ->and($status->deletionScheduledFor->equalTo(Carbon::parse('2026-10-20T10:00:00+00:00')))->toBeTrue()
            ->and($status->exists)->toBeTrue()->and($status->suspended)->toBeFalse()
            ->and($status->isActive())->toBeFalse();
    });

    it('has no deletion by default, and the account is active', function () {
        $status = Identity::client()->accountStatus(DEL_USER);

        expect($status->deletion)->toBeNull()->and($status->deletionScheduledFor)->toBeNull()->and($status->isActive())->toBeTrue();
    });
});
