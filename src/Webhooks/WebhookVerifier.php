<?php

namespace AutoGteck\IdentityConnector\Webhooks;

use AutoGteck\IdentityConnector\Jwt\KeySetProvider;
use AutoGteck\IdentityConnector\Jwt\KeySetUnavailable;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Vérifie la signature d'un webhook d'Identity (AR-087) avec les clés publiques du JWKS, comme un access token : type
 * `identity-webhook+jwt`, clé connue, signature, émetteur, audience de ce produit (un webhook destiné à un autre produit est
 * refusé), fenêtre de validité avec la tolérance d'horloge, et empreinte du corps reçu.
 */
final class WebhookVerifier
{
    public function __construct(
        private readonly KeySetProvider $keys,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $toleranceSeconds = 60,
    ) {}

    /**
     * @throws KeySetUnavailable Identity injoignable et aucune clé connue : le produit répond 503, Identity réessaiera
     */
    public function verify(?string $jwt, string $body): bool
    {
        $header = $this->header($jwt);

        if ($header === null || ($header['typ'] ?? null) !== WebhookSignature::TYPE || ! is_string($header['kid'] ?? null)) {
            return false;
        }

        $key = $this->keys->key($header['kid']);

        if ($key === null) {
            return false;
        }

        JWT::$leeway = $this->toleranceSeconds;
        JWT::$timestamp = Carbon::now()->getTimestamp();

        try {
            $claims = (array) JWT::decode((string) $jwt, $key);
        } catch (Throwable) {
            return false;
        } finally {
            JWT::$timestamp = null;
        }

        return ($claims['iss'] ?? null) === $this->issuer
            && ($claims['aud'] ?? null) === $this->audience
            && isset($claims['exp'], $claims['iat'])
            && is_string($claims['body'] ?? null)
            && hash_equals(WebhookSignature::digest($body), $claims['body']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function header(?string $jwt): ?array
    {
        $segments = explode('.', (string) $jwt);

        if (count($segments) !== 3) {
            return null;
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($header) ? $header : null;
    }
}
