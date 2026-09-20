<?php

namespace AutoReflex\IdentityConnector\Jwt;

use Carbon\Carbon;
use Firebase\JWT\JWT;
use Throwable;
use UnexpectedValueException;

/**
 * Vérifie un access token Identity sans appeler Identity : signature (JWKS), émetteur, audience,
 * expiration (AR-052). La révocation n'est pas contrôlée : la durée de vie courte des tokens fait foi.
 */
final class JwtVerifier
{
    public const ALGORITHM = 'RS256';

    public function __construct(
        private readonly KeySetProvider $keys,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly int $leewaySeconds = 30,
    ) {}

    /**
     * @throws InvalidAccessToken
     * @throws KeySetUnavailable
     */
    public function verify(string $jwt): VerifiedToken
    {
        $key = $this->keys->key($this->keyId($jwt) ?? throw new InvalidAccessToken('Missing key id.'))
            ?? throw new InvalidAccessToken('Unknown key.');

        JWT::$leeway = $this->leewaySeconds;
        JWT::$timestamp = Carbon::now()->getTimestamp();

        try {
            $claims = (array) JWT::decode($jwt, $key);
        } catch (Throwable $exception) {
            throw new InvalidAccessToken($exception->getMessage(), previous: $exception);
        } finally {
            JWT::$timestamp = null;
        }

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new InvalidAccessToken('Unexpected issuer.');
        }

        if (($claims['aud'] ?? null) !== $this->audience) {
            throw new InvalidAccessToken('Unexpected audience.');
        }

        foreach (['sub', 'jti', 'client_id', 'exp'] as $required) {
            if (! isset($claims[$required])) {
                throw new InvalidAccessToken("Missing claim {$required}.");
            }
        }

        return new VerifiedToken(
            subject: (string) $claims['sub'],
            audience: $this->audience,
            clientId: (string) $claims['client_id'],
            tokenId: (string) $claims['jti'],
            scopes: array_values(array_filter(explode(' ', (string) ($claims['scope'] ?? '')))),
            expiresAt: (int) $claims['exp'],
            roles: self::roles($claims['roles'] ?? null),
        );
    }

    /**
     * Le claim `roles` est une liste de noms ; toute autre forme est ignorée (aucun rôle), jamais interprétée.
     *
     * @return list<string>
     */
    private static function roles(mixed $claim): array
    {
        if (! is_array($claim)) {
            return [];
        }

        return array_values(array_unique(array_filter($claim, fn (mixed $role): bool => is_string($role) && $role !== '')));
    }

    /**
     * L'algorithme est imposé : un en-tête qui en annonce un autre (`none`, HS256 signé avec la clé
     * publique…) est refusé avant toute vérification, et aucune clé n'est jamais lue dans le token.
     */
    private function keyId(string $jwt): ?string
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new InvalidAccessToken('Malformed token.');
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($segments[0]), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidAccessToken('Malformed token header.');
        }

        if (! is_array($header) || ($header['alg'] ?? null) !== self::ALGORITHM) {
            throw new InvalidAccessToken('Unexpected algorithm.');
        }

        return is_string($header['kid'] ?? null) && $header['kid'] !== '' ? $header['kid'] : null;
    }

    /**
     * @throws UnexpectedValueException
     */
    public static function extractBearer(?string $header): string
    {
        if ($header === null || ! preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw new UnexpectedValueException('Missing bearer token.');
        }

        return $matches[1];
    }
}
