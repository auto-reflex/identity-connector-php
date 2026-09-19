<?php

namespace Tests\Support;

use AutoReflex\IdentityConnector\Jwt\KeySetProvider;
use AutoReflex\IdentityConnector\Testing\SigningKey;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/**
 * Trousseau figé pour tester le vérificateur sans réseau.
 */
final class StaticKeySet implements KeySetProvider
{
    /** @var array<string, Key> */
    private array $keys = [];

    public function __construct(SigningKey ...$keys)
    {
        foreach ($keys as $key) {
            $this->keys[$key->kid] = JWK::parseKey($key->jwk(), 'RS256');
        }
    }

    public function key(string $kid): ?Key
    {
        return $this->keys[$kid] ?? null;
    }
}
