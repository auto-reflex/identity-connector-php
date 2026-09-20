<?php

use AutoGteck\IdentityConnector\Profiles\IdentityUser;
use AutoGteck\IdentityConnector\Profiles\ProfileStore;
use Carbon\Carbon;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Workbench\App\EloquentProfileStore;
use Workbench\App\Models\Profile;

const PERSON = '01J0USER00000000000000000A';

function authorization(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autotrackly-api')->user(PERSON, name: 'Camille Durand', locale: 'en');
});

afterEach(fn () => Carbon::setTestNow());

it('creates the local profile at the first request from /userinfo, once', function () {
    $token = $this->identity->tokenFor(PERSON);

    $first = $this->getJson('/api/me', authorization($token))->assertOk();
    $second = $this->getJson('/api/me', authorization($token))->assertOk();

    expect($first->json('identity_user_id'))->toBe(PERSON)->and($first->json('display_name'))->toBe('Camille Durand')
        ->and($second->json('id'))->toBe($first->json('id'))
        ->and(Profile::count())->toBe(1)
        ->and(Profile::first()->locale)->toBe('en')
        ->and($this->identity->userInfoCalls())->toBe(1);
});

it('makes the profile the user of the default guard, so that the Gate and the policies see it', function () {
    $this->getJson('/api/guard-user/'.PERSON, authorization($this->identity->tokenFor(PERSON)))
        ->assertOk()
        ->assertJson(['guard_user' => PERSON, 'gate_allows' => true]);

    $this->getJson('/api/guard-user/someone-else', authorization($this->identity->tokenFor(PERSON)))
        ->assertOk()
        ->assertJson(['gate_allows' => false]);
});

it('authenticates before throttle, so that a named limiter keyed on the user sees the person at the first request', function () {
    $this->getJson('/api/throttled', authorization($this->identity->tokenFor(PERSON)))->assertOk();

    expect(config('by-person.key'))->toBe(PERSON);
});

it('keeps working for a known person while Identity is down (degraded mode)', function () {
    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertOk();
    $token = $this->identity->tokenFor(PERSON);
    $this->identity->goDown();

    $this->getJson('/api/me', authorization($token))->assertOk();
    expect($this->identity->userInfoCalls())->toBe(1);
});

it('answers 503 and creates nothing when Identity is down at the first request, then recovers', function () {
    $token = $this->identity->tokenFor(PERSON);
    $this->getJson('/api/whoami', authorization($token))->assertOk();
    $this->identity->goDown();

    $this->getJson('/api/me', authorization($token))->assertStatus(503)->assertJson(['error' => 'identity_unavailable']);
    expect(Profile::count())->toBe(0);

    $this->identity->comeBack();
    $this->getJson('/api/me', authorization($token))->assertOk();
    expect(Profile::count())->toBe(1);
});

it('refuses with 401 and creates nothing when Identity rejects the token', function () {
    $this->identity->revoke(PERSON);

    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertUnauthorized();

    expect(Profile::count())->toBe(0);
});

it('refuses a person whose email Identity does not vouch for', function () {
    $this->identity->user(PERSON, emailVerified: false);

    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertForbidden()->assertJson(['error' => 'email_unverified']);

    expect(Profile::count())->toBe(0);
});

it('refuses a service token: there is no person to give a profile to', function () {
    $token = $this->identity->serviceTokenFor('autotrackly-api-service', ['profile']);

    $this->getJson('/api/me', authorization($token))->assertForbidden()->assertJson(['error' => 'user_token_required']);
});

it('refuses a suspended product profile, without touching the others', function () {
    $this->identity->user('01J0OTHER0000000000000000B', name: 'Alex Martin');
    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertOk();
    Profile::where('identity_user_id', PERSON)->update(['product_suspended_at' => now()]);

    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertForbidden()->assertJson(['error' => 'profile_suspended']);
    $this->getJson('/api/me', authorization($this->identity->tokenFor('01J0OTHER0000000000000000B')))->assertOk();
});

it('does not trust a /userinfo response about someone else', function () {
    $this->identity->user('01J0OTHER0000000000000000B', name: 'Alex Martin');
    // Le token parle de PERSON, mais Identity (compromis ou mal configuré) répondrait pour une autre personne.
    $this->identity->forceUserInfo(['sub' => '01J0OTHER0000000000000000B', 'name' => 'Alex Martin', 'email' => 'a@example.test', 'email_verified' => true]);

    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertUnauthorized();

    expect(Profile::count())->toBe(0);
});

it('reads back the profile when a concurrent request created it first', function () {
    // Simule la course : `find` ne voit rien, `create` échoue sur l'unicité car l'autre requête a gagné.
    $this->app->instance(ProfileStore::class, new class extends EloquentProfileStore
    {
        private int $finds = 0;

        public function find(string $identityUserId): ?Authenticatable
        {
            return $this->finds++ === 0 ? null : parent::find($identityUserId);
        }

        public function create(IdentityUser $user): Authenticatable
        {
            parent::create($user);

            return parent::create($user);
        }
    });

    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON)))->assertOk()->assertJson(['identity_user_id' => PERSON]);

    expect(Profile::count())->toBe(1);
});

it('lets a real unique violation surface when the profile cannot be read back', function () {
    $this->app->instance(ProfileStore::class, new class extends EloquentProfileStore
    {
        public function find(string $identityUserId): ?Authenticatable
        {
            return null;
        }

        public function create(IdentityUser $user): Authenticatable
        {
            throw new UniqueConstraintViolationException('testing', 'insert', [], new Exception('duplicate'));
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON))))->toThrow(UniqueConstraintViolationException::class);
});

it('does not create a profile when the token lacks the profile scope', function () {
    $this->getJson('/api/me', authorization($this->identity->tokenFor(PERSON, ['email'])))->assertForbidden();

    expect(Profile::count())->toBe(0);
});
