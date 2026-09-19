<?php

use Carbon\Carbon;
use Firebase\JWT\JWT;

const USER_ID = '01J0USER00000000000000000A';

function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->identity = $this->fakeIdentity('autotrackly-api')->user(USER_ID);
});

afterEach(fn () => Carbon::setTestNow());

it('authenticates a valid token and exposes it through the facade', function () {
    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertOk()->assertJson(['sub' => USER_ID]);
});

describe('answers 401 with a WWW-Authenticate header', function () {
    it('without a token or with a malformed header', function (?string $header) {
        $response = $this->getJson('/api/whoami', $header === null ? [] : ['Authorization' => $header])->assertUnauthorized();

        expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer error="invalid_token"');
    })->with([null, 'Bearer', 'Basic abc', 'Bearer not-a-jwt', 'Bearer a.b.c']);

    it('to a token for another audience, notably identity-api', function () {
        $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID, claims: ['aud' => 'identity-api'])))->assertUnauthorized();
    });

    it('to an expired token', function () {
        $token = $this->identity->tokenFor(USER_ID, claims: ['exp' => Carbon::now()->getTimestamp() - 60]);

        $this->getJson('/api/whoami', bearer($token))->assertUnauthorized();
    });

    it('to a token from another issuer', function () {
        $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID, claims: ['iss' => 'https://evil.test'])))->assertUnauthorized();
    });

    it('to a token signed by an unknown key', function () {
        $pem = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($pem, $private);
        $forged = JWT::encode(['iss' => 'https://identity.test', 'aud' => 'autotrackly-api', 'sub' => USER_ID, 'jti' => 'x', 'client_id' => 'c', 'exp' => Carbon::now()->getTimestamp() + 900], $private, 'RS256', 'test-key-1');

        $this->getJson('/api/whoami', bearer($forged))->assertUnauthorized();
    });
});

it('answers 403 when a scope is missing', function () {
    $response = $this->getJson('/api/needs-scope', bearer($this->identity->tokenFor(USER_ID, ['profile', 'email'])))->assertForbidden();

    expect($response->headers->get('WWW-Authenticate'))->toContain('insufficient_scope')->toContain('vehicles:read');
    $this->getJson('/api/needs-scope', bearer($this->identity->tokenFor(USER_ID, ['profile', 'vehicles:read'])))->assertOk();
});

it('answers 503 rather than 401 when Identity is unreachable and no key was ever fetched', function () {
    $this->identity->goDown();

    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertStatus(503)->assertJson(['error' => 'identity_unavailable']);
});

it('keeps authenticating when Identity goes down after the keys were cached', function () {
    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertOk();
    $this->identity->goDown();
    Carbon::setTestNow(now()->addMinutes(10));

    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertOk();
});

it('follows a key rotation without a restart', function () {
    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertOk();
    $this->identity->rotateKey();

    // Moins d'une minute après le dernier chargement : la clé inconnue ne provoque pas de rechargement.
    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertUnauthorized();

    Carbon::setTestNow(now()->addSeconds(61));
    $this->getJson('/api/whoami', bearer($this->identity->tokenFor(USER_ID)))->assertOk();
});
