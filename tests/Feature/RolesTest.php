<?php

use Carbon\Carbon;

function withToken(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autotrackly-api')->user('01J0USER00000000000000000A');
    $this->token = fn (array $roles = []) => $this->identity->tokenFor('01J0USER00000000000000000A', claims: $roles === [] ? [] : ['roles' => $roles]);
});

afterEach(fn () => Carbon::setTestNow());

it('lets a token that carries the role through', function () {
    $this->getJson('/api/team/admin', withToken(($this->token)(['admin'])))->assertOk();
});

it('answers 403 insufficient_role without the role, or with none at all', function () {
    $this->getJson('/api/team/admin', withToken(($this->token)()))->assertForbidden()->assertJson(['error' => 'insufficient_role']);
    $this->getJson('/api/team/admin', withToken(($this->token)(['moderator'])))->assertForbidden()->assertJson(['error' => 'insufficient_role']);
});

it('accepts any of several listed roles', function () {
    $this->getJson('/api/team/any', withToken(($this->token)(['moderator'])))->assertOk()->assertJson(['roles' => ['moderator']]);
    $this->getJson('/api/team/any', withToken(($this->token)(['viewer'])))->assertForbidden();
});

it('still answers 401 first, before any role check, for a missing or invalid token', function () {
    $this->getJson('/api/team/admin')->assertUnauthorized();
    $this->getJson('/api/team/admin', withToken('a.b.c'))->assertUnauthorized();
});

it('answers 401 rather than trusting anything when the role check runs without authentication', function () {
    $this->getJson('/api/team/no-auth', withToken(($this->token)(['admin'])))->assertUnauthorized();
});

it('ignores a roles claim that is not a list of names', function (mixed $claim) {
    $token = $this->identity->tokenFor('01J0USER00000000000000000A', claims: ['roles' => $claim]);

    $this->getJson('/api/team/admin', withToken($token))->assertForbidden();
})->with(['a string' => ['admin'], 'a number' => [1], 'a map' => [['admin' => true]], 'nulls and empties' => [[null, '', 0, false]]]);

it('exposes the roles to the application through the facade', function () {
    $this->getJson('/api/team/has/admin', withToken(($this->token)(['admin'])))->assertOk()->assertJson(['has' => true]);
    $this->getJson('/api/team/has/admin', withToken(($this->token)()))->assertOk()->assertJson(['has' => false]);
});
