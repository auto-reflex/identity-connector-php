<?php

namespace AutoReflex\IdentityConnector\Jwt;

use Carbon\Carbon;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Lit le JWKS d'Identity depuis l'URL configurée (jamais depuis le token) et le garde en cache (AR-052).
 *
 * - fraîcheur : `max-age` de la réponse, bornée, 300 s par défaut ;
 * - `kid` inconnu : un rechargement au plus par minute (une clé fraîchement tournée passe, un flot de
 *   faux `kid` ne martèle pas Identity) ;
 * - Identity injoignable : les dernières clés connues servent pendant `staleSeconds` (24 h), avec une
 *   pause avant chaque nouvelle tentative.
 */
final class RemoteKeySet implements KeySetProvider
{
    private const CACHE_KEY = 'identity-connector:jwks';

    private const RELOAD_KEY = 'identity-connector:jwks:reload';

    private const BACKOFF_KEY = 'identity-connector:jwks:backoff';

    private const DEFAULT_MAX_AGE = 300;

    private const MAX_BODY_BYTES = 65536;

    public function __construct(
        private readonly Cache $cache,
        private readonly Http $http,
        private readonly string $url,
        private readonly int $staleSeconds = 86400,
        private readonly int $reloadIntervalSeconds = 60,
        private readonly int $backoffSeconds = 30,
        private readonly int $timeoutSeconds = 3,
    ) {}

    public function key(string $kid): ?Key
    {
        $set = $this->cached();

        if ($set === null || $set['fresh_until'] <= $this->now()) {
            $set = $this->refreshOrFallBack($set);
        }

        if (! isset($set['keys'][$kid]) && $this->mayReload()) {
            $set = $this->tryFetch() ?? $set;
        }

        return isset($set['keys'][$kid]) ? $this->toKey($set['keys'][$kid]) : null;
    }

    /**
     * @param  array{keys: array<string, array<string, string>>, fresh_until: int, fetched_at: int}|null  $stale
     * @return array{keys: array<string, array<string, string>>, fresh_until: int, fetched_at: int}
     *
     * @throws KeySetUnavailable
     */
    private function refreshOrFallBack(?array $stale): array
    {
        $usable = $stale !== null && $this->now() < $stale['fetched_at'] + $this->staleSeconds;

        if ($usable && $this->cache->has(self::BACKOFF_KEY)) {
            return $stale;
        }

        $fresh = $this->tryFetch();

        if ($fresh !== null) {
            return $fresh;
        }

        if (! $usable) {
            throw new KeySetUnavailable('No usable key set: Identity is unreachable and the cache is empty or too old.');
        }

        $this->cache->put(self::BACKOFF_KEY, 1, $this->backoffSeconds);

        return $stale;
    }

    private function mayReload(): bool
    {
        // `add` ne réussit qu'une fois par intervalle, même sous concurrence.
        return $this->cache->add(self::RELOAD_KEY, 1, $this->reloadIntervalSeconds);
    }

    /**
     * @return array{keys: array<string, array<string, string>>, fresh_until: int, fetched_at: int}|null
     */
    private function tryFetch(): ?array
    {
        // Toute tentative, réussie ou non, compte dans la limite d'un rechargement forcé par intervalle.
        $this->cache->put(self::RELOAD_KEY, 1, $this->reloadIntervalSeconds);

        try {
            $response = $this->http->timeout($this->timeoutSeconds)->connectTimeout(2)->withoutRedirecting()->acceptJson()->get($this->url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful() || strlen($response->body()) > self::MAX_BODY_BYTES) {
            return null;
        }

        $keys = $this->sanitize($response->json('keys'));

        if ($keys === []) {
            return null;
        }

        $now = $this->now();
        $set = ['keys' => $keys, 'fresh_until' => $now + $this->maxAge($response->header('Cache-Control')), 'fetched_at' => $now];

        $this->cache->put(self::CACHE_KEY, $set, $this->staleSeconds + 3600);
        $this->cache->forget(self::BACKOFF_KEY);

        return $set;
    }

    /**
     * Ne garde que les clés RSA de signature RS256 portant un `kid` : le reste du JWKS est ignoré.
     *
     * @return array<string, array<string, string>>
     */
    private function sanitize(mixed $keys): array
    {
        $accepted = [];

        foreach (is_array($keys) ? $keys : [] as $jwk) {
            if (! is_array($jwk)
                || ($jwk['kty'] ?? null) !== 'RSA'
                || ! is_string($jwk['kid'] ?? null) || $jwk['kid'] === ''
                || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)
                || (isset($jwk['alg']) && $jwk['alg'] !== JwtVerifier::ALGORITHM)
                || (isset($jwk['use']) && $jwk['use'] !== 'sig')) {
                continue;
            }

            $accepted[$jwk['kid']] = ['kty' => 'RSA', 'kid' => $jwk['kid'], 'n' => $jwk['n'], 'e' => $jwk['e']];
        }

        return $accepted;
    }

    private function maxAge(?string $cacheControl): int
    {
        $maxAge = $cacheControl !== null && preg_match('/max-age=(\d+)/i', $cacheControl, $matches) ? (int) $matches[1] : self::DEFAULT_MAX_AGE;

        return max(30, min(3600, $maxAge));
    }

    /**
     * @return array{keys: array<string, array<string, string>>, fresh_until: int, fetched_at: int}|null
     */
    private function cached(): ?array
    {
        $set = $this->cache->get(self::CACHE_KEY);

        return is_array($set) && isset($set['keys'], $set['fresh_until'], $set['fetched_at']) ? $set : null;
    }

    /**
     * @param  array<string, string>  $jwk
     */
    private function toKey(array $jwk): ?Key
    {
        try {
            return JWK::parseKey($jwk, JwtVerifier::ALGORITHM);
        } catch (Throwable) {
            return null;
        }
    }

    private function now(): int
    {
        return Carbon::now()->getTimestamp();
    }
}
