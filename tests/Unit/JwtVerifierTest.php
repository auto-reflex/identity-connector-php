<?php

use AutoGteck\IdentityConnector\Jwt\InvalidAccessToken;
use AutoGteck\IdentityConnector\Jwt\JwtVerifier;
use AutoGteck\IdentityConnector\Testing\SigningKey;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Tests\Support\StaticKeySet;

const ISSUER = 'https://identity.test';
const AUDIENCE = 'autotrackly-api';

/**
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function claims(array $override = []): array
{
    $now = Carbon::now()->getTimestamp();

    return array_filter([
        'iss' => ISSUER,
        'aud' => AUDIENCE,
        'sub' => '01J0USER00000000000000000A',
        'jti' => 'jti-1',
        'client_id' => 'autotrackly-mobile',
        'scope' => 'profile email autotrackly:access',
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + 900,
        ...$override,
    ], fn ($value) => $value !== null);
}

function base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

beforeEach(function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $this->key = SigningKey::generate('key-1');
    $this->verifier = new JwtVerifier(new StaticKeySet($this->key), ISSUER, AUDIENCE);
});

afterEach(fn () => Carbon::setTestNow());

it('verifies a valid token and exposes its claims', function () {
    $token = $this->verifier->verify($this->key->sign(claims()));

    expect($token->subject)->toBe('01J0USER00000000000000000A')
        ->and($token->audience)->toBe(AUDIENCE)
        ->and($token->clientId)->toBe('autotrackly-mobile')
        ->and($token->tokenId)->toBe('jti-1')
        ->and($token->scopes)->toBe(['profile', 'email', 'autotrackly:access'])
        ->and($token->hasScope('email'))->toBeTrue()
        ->and($token->hasScope('vehicles:read'))->toBeFalse()
        ->and($token->isClientToken())->toBeFalse()
        ->and($token->expiresAt)->toBe(Carbon::now()->getTimestamp() + 900);
});

it('recognizes a service token by its subject', function () {
    $token = $this->verifier->verify($this->key->sign(claims(['sub' => 'autotrackly-api-service', 'client_id' => 'autotrackly-api-service'])));

    expect($token->isClientToken())->toBeTrue();
});

it('tolerates no scope claim', function () {
    expect($this->verifier->verify($this->key->sign(claims(['scope' => null])))->scopes)->toBe([]);
});

describe('rejects', function () {
    it('a tampered payload', function () {
        [$header, , $signature] = explode('.', $this->key->sign(claims()));
        $forged = base64url(json_encode(claims(['sub' => 'someone-else'])));

        expect(fn () => $this->verifier->verify("{$header}.{$forged}.{$signature}"))->toThrow(InvalidAccessToken::class);
    });

    it('a token signed by another key under the same kid', function () {
        $impostor = SigningKey::generate('key-1');

        expect(fn () => $this->verifier->verify($impostor->sign(claims())))->toThrow(InvalidAccessToken::class);
    });

    it('alg none', function () {
        $header = base64url(json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => 'key-1']));
        $payload = base64url(json_encode(claims()));

        expect(fn () => $this->verifier->verify("{$header}.{$payload}."))->toThrow(InvalidAccessToken::class, 'Unexpected algorithm.');
    });

    it('an HS256 token, whatever the secret (algorithm confusion)', function () {
        $token = JWT::encode(claims(), $this->key->jwk()['n'], 'HS256', 'key-1');

        expect(fn () => $this->verifier->verify($token))->toThrow(InvalidAccessToken::class, 'Unexpected algorithm.');
    });

    it('another RSA algorithm than RS256', function () {
        $pem = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($pem, $private);

        expect(fn () => $this->verifier->verify(JWT::encode(claims(), $private, 'RS512', 'key-1')))->toThrow(InvalidAccessToken::class, 'Unexpected algorithm.');
    });

    it('a token without key id', function () {
        $pem = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($pem, $private);

        expect(fn () => $this->verifier->verify(JWT::encode(claims(), $private, 'RS256')))->toThrow(InvalidAccessToken::class, 'Missing key id.');
    });

    it('an unknown key id', function () {
        $other = SigningKey::generate('rotated-away');

        expect(fn () => $this->verifier->verify($other->sign(claims())))->toThrow(InvalidAccessToken::class, 'Unknown key.');
    });

    it('a wrong issuer', function () {
        expect(fn () => $this->verifier->verify($this->key->sign(claims(['iss' => 'https://evil.test']))))->toThrow(InvalidAccessToken::class, 'Unexpected issuer.');
    });

    it('a token for another audience, including identity-api', function (string $audience) {
        expect(fn () => $this->verifier->verify($this->key->sign(claims(['aud' => $audience]))))->toThrow(InvalidAccessToken::class, 'Unexpected audience.');
    })->with(['identity-api', 'map-api', 'autotrackly-api-2', '']);

    it('an audience list or a missing audience', function () {
        expect(fn () => $this->verifier->verify($this->key->sign(claims(['aud' => [AUDIENCE]]))))->toThrow(InvalidAccessToken::class)
            ->and(fn () => $this->verifier->verify($this->key->sign(claims(['aud' => null]))))->toThrow(InvalidAccessToken::class);
    });

    it('an expired token, beyond the 30 s leeway', function () {
        expect(fn () => $this->verifier->verify($this->key->sign(claims(['exp' => Carbon::now()->getTimestamp() - 31]))))->toThrow(InvalidAccessToken::class);
    });

    it('a token not yet valid, beyond the leeway', function () {
        expect(fn () => $this->verifier->verify($this->key->sign(claims(['nbf' => Carbon::now()->getTimestamp() + 31]))))->toThrow(InvalidAccessToken::class);
    });

    it('a token without a claim', function (string $missing) {
        expect(fn () => $this->verifier->verify($this->key->sign(claims([$missing => null]))))->toThrow(InvalidAccessToken::class);
    })->with(['sub', 'jti', 'client_id', 'exp']);

    it('malformed input', function (string $input) {
        expect(fn () => $this->verifier->verify($input))->toThrow(InvalidAccessToken::class);
    })->with(['', 'a.b', 'a.b.c.d', 'not-a-jwt', '..', 'e30.e30.e30', '!!!.e30.e30']);
});

it('accepts a token slightly expired or not yet valid, within the leeway', function () {
    $now = Carbon::now()->getTimestamp();

    expect($this->verifier->verify($this->key->sign(claims(['exp' => $now - 10])))->tokenId)->toBe('jti-1')
        ->and($this->verifier->verify($this->key->sign(claims(['nbf' => $now + 10, 'iat' => $now + 10])))->tokenId)->toBe('jti-1');
});

it('does not leak the verification clock to other JWT users', function () {
    $this->verifier->verify($this->key->sign(claims()));

    expect(JWT::$timestamp)->toBeNull();
});

it('extracts a bearer token', function () {
    expect(JwtVerifier::extractBearer('Bearer abc.def.ghi'))->toBe('abc.def.ghi')
        ->and(JwtVerifier::extractBearer('bearer abc'))->toBe('abc')
        ->and(fn () => JwtVerifier::extractBearer(null))->toThrow(UnexpectedValueException::class)
        ->and(fn () => JwtVerifier::extractBearer('Basic abc'))->toThrow(UnexpectedValueException::class)
        ->and(fn () => JwtVerifier::extractBearer('Bearer a b'))->toThrow(UnexpectedValueException::class);
});
