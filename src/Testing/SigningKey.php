<?php

namespace AutoGteck\IdentityConnector\Testing;

use Firebase\JWT\JWT;
use RuntimeException;

/**
 * Paire de clés RSA de test qui joue le rôle du trousseau d'Identity : signe des tokens et publie son JWKS.
 * Réservé aux tests (les produits l'utilisent avec `FakesIdentity`).
 */
final class SigningKey
{
    private function __construct(
        public readonly string $kid,
        private readonly string $privatePem,
        private readonly string $modulus,
        private readonly string $exponent,
    ) {}

    public static function generate(string $kid = 'test-key-1'): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false || ! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('Unable to generate a test RSA key.');
        }

        $rsa = openssl_pkey_get_details($key)['rsa'];

        return new self($kid, $pem, JWT::urlsafeB64Encode($rsa['n']), JWT::urlsafeB64Encode($rsa['e']));
    }

    /**
     * @return array<string, string>
     */
    public function jwk(): array
    {
        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid, 'n' => $this->modulus, 'e' => $this->exponent];
    }

    /**
     * Signe comme Identity : un access token (`typ` = `at+jwt`) par défaut.
     *
     * @param  array<string, mixed>  $claims
     * @param  array<string, mixed>  $header  en-têtes supplémentaires ou remplacés (ex. `typ` d'un webhook)
     */
    public function sign(array $claims, array $header = []): string
    {
        return JWT::encode($claims, $this->privatePem, 'RS256', $this->kid, ['typ' => 'at+jwt', ...$header]);
    }
}
