<?php

namespace AutoReflex\IdentityConnector\Client;

use AutoReflex\IdentityConnector\Profiles\IdentityUser;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use JsonException;

/**
 * Client de l'API d'Identity (AR-052) : timeouts courts, une reprise sur les lectures, erreurs typées.
 * Aucun token n'est jamais écrit dans un log ni dans un message d'exception.
 */
class IdentityClient
{
    public const IDENTITY_AUDIENCE = 'identity-api';

    private const TOKEN_EXCHANGE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    private const ACCESS_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:access_token';

    /** Les tokens obtenus sont abandonnés un peu avant leur expiration, pour ne jamais en présenter un périmé. */
    private const EXPIRY_MARGIN = 30;

    /**
     * @param  array<string, string>  $exchangeSecrets  secrets des clients confidentiels, par `client_id`
     */
    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly StringEncrypter $encrypter,
        private readonly string $baseUrl,
        private readonly int $timeout = 3,
        private readonly int $connectTimeout = 2,
        private readonly ?string $serviceClientId = null,
        private readonly ?string $serviceClientSecret = null,
        private readonly array $exchangeSecrets = [],
    ) {}

    /**
     * Ce qu'Identity dit de la personne du token produit (`/userinfo`, AR-048).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function userInfo(string $accessToken): IdentityUser
    {
        $claims = $this->get($accessToken, '/userinfo')->json();

        if (! is_array($claims) || ! isset($claims['sub']) || ! is_string($claims['sub'])) {
            throw new IdentityUnavailable('Identity returned an unusable /userinfo response.');
        }

        return IdentityUser::fromUserInfo($claims);
    }

    /**
     * Organisations de la personne du token produit, avec son rôle (`GET /api/v1/organizations`).
     * Le token `identity-api` est obtenu par échange (AR-047) et gardé en cache jusqu'à son expiration.
     *
     * @return list<Organization>
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function organizations(string $productToken): array
    {
        $data = $this->asPerson($productToken, '/api/v1/organizations')->json('data');

        return is_array($data) ? array_values(array_map(fn (array $item) => Organization::fromArray($item), $data)) : [];
    }

    /**
     * Détail d'une organisation et de ses membres ; `null` si elle n'existe pas ou si la personne n'en
     * est pas membre (Identity ne distingue pas les deux cas).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function organization(string $productToken, string $organizationId): ?Organization
    {
        try {
            $data = $this->asPerson($productToken, '/api/v1/organizations/'.rawurlencode($organizationId))->json('data');
        } catch (IdentityRejected $rejected) {
            if ($rejected->status === 404) {
                return null;
            }

            throw $rejected;
        }

        return is_array($data) ? Organization::fromArray($data) : null;
    }

    /**
     * Existence et suspension d'un compte, en service à service (`client_credentials`, scope `accounts:status`).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function accountStatus(string $userId): AccountStatus
    {
        $data = $this->asService('accounts:status', '/api/v1/accounts/'.rawurlencode($userId).'/status')->json();

        if (! is_array($data) || ! isset($data['id'], $data['exists'], $data['suspended'])) {
            throw new IdentityUnavailable('Identity returned an unusable account status.');
        }

        return new AccountStatus((string) $data['id'], (bool) $data['exists'], (bool) $data['suspended']);
    }

    /**
     * Token `identity-api` d'une personne, par échange RFC 8693 depuis son token produit (AR-047).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function identityToken(string $productToken, bool $fresh = false): string
    {
        $key = 'identity-connector:exchange:'.hash('sha256', $productToken);

        if (! $fresh && ($cached = $this->cachedToken($key)) !== null) {
            return $cached;
        }

        $clientId = $this->claimOf($productToken, 'client_id');
        $response = $this->send(fn (PendingRequest $request) => $request->asForm()->post($this->url('/oauth/token'), array_filter([
            'grant_type' => self::TOKEN_EXCHANGE,
            'client_id' => $clientId,
            'client_secret' => $this->exchangeSecrets[$clientId] ?? null,
            'subject_token' => $productToken,
            'subject_token_type' => self::ACCESS_TOKEN_TYPE,
            'audience' => self::IDENTITY_AUDIENCE,
        ])), retry: false);

        return $this->rememberToken($key, $response);
    }

    /**
     * Token d'un service (`client_credentials`) pour les scopes demandés, sans personne.
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function serviceToken(string $scope, bool $fresh = false): string
    {
        if ($this->serviceClientId === null || $this->serviceClientSecret === null) {
            throw new IdentityRejected(401, 'The service client is not configured (IDENTITY_SERVICE_CLIENT_ID and IDENTITY_SERVICE_CLIENT_SECRET).');
        }

        $key = 'identity-connector:service:'.hash('sha256', $this->serviceClientId.'|'.$scope);

        if (! $fresh && ($cached = $this->cachedToken($key)) !== null) {
            return $cached;
        }

        $response = $this->send(fn (PendingRequest $request) => $request->asForm()->post($this->url('/oauth/token'), [
            'grant_type' => 'client_credentials',
            'client_id' => $this->serviceClientId,
            'client_secret' => $this->serviceClientSecret,
            'scope' => $scope,
        ]), retry: false);

        return $this->rememberToken($key, $response);
    }

    /**
     * GET avec le token `identity-api` de la personne. Un 401 (token en cache révoqué entre-temps) provoque un
     * seul nouvel échange.
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    private function asPerson(string $productToken, string $path): Response
    {
        try {
            return $this->get($this->identityToken($productToken), $path);
        } catch (IdentityRejected $rejected) {
            if ($rejected->status !== 401) {
                throw $rejected;
            }
        }

        return $this->get($this->identityToken($productToken, fresh: true), $path);
    }

    /**
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    private function asService(string $scope, string $path): Response
    {
        try {
            return $this->get($this->serviceToken($scope), $path);
        } catch (IdentityRejected $rejected) {
            if ($rejected->status !== 401) {
                throw $rejected;
            }
        }

        return $this->get($this->serviceToken($scope, fresh: true), $path);
    }

    private function cachedToken(string $key): ?string
    {
        $stored = $this->cache->get($key);

        if (! is_string($stored)) {
            return null;
        }

        try {
            return $this->encrypter->decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Garde le token chiffré jusqu'à 30 s avant son expiration et le renvoie.
     *
     * @throws IdentityUnavailable
     */
    private function rememberToken(string $key, Response $response): string
    {
        $token = $response->json('access_token');
        $lifetime = $response->json('expires_in');

        if (! is_string($token) || $token === '' || ! is_int($lifetime)) {
            throw new IdentityUnavailable('Identity returned an unusable token response.');
        }

        if ($lifetime - self::EXPIRY_MARGIN > 0) {
            $this->cache->put($key, $this->encrypter->encryptString($token), $lifetime - self::EXPIRY_MARGIN);
        }

        return $token;
    }

    /**
     * Lit un claim du token produit sans le vérifier : il l'a déjà été par le connecteur, et Identity
     * le revérifie de toute façon à l'échange.
     */
    private function claimOf(string $jwt, string $claim): string
    {
        $segments = explode('.', $jwt);

        try {
            $payload = count($segments) === 3 ? json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $payload = null;
        }

        if (! is_array($payload) || ! is_string($payload[$claim] ?? null)) {
            throw new IdentityRejected(400, 'The product token has no readable '.$claim.' claim.');
        }

        return $payload[$claim];
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    protected function get(string $accessToken, string $path, array $query = []): Response
    {
        return $this->send(fn (PendingRequest $request) => $request->withToken($accessToken)->get($this->url($path), $query), retry: true);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    protected function send(callable $call, bool $retry): Response
    {
        $attempts = $retry ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $call($this->pending());
            } catch (ConnectionException) {
                if ($attempt < $attempts) {
                    continue;
                }

                throw new IdentityUnavailable('Identity is unreachable.');
            }

            if ($response->serverError() || $response->status() === 429) {
                if ($attempt < $attempts) {
                    continue;
                }

                throw new IdentityUnavailable("Identity answered HTTP {$response->status()}.");
            }

            // Tout ce qui n'est pas un succès (dont une redirection, jamais suivie) est un refus.
            if (! $response->successful()) {
                throw new IdentityRejected($response->status(), error: is_string($response->json('error')) ? $response->json('error') : null);
            }

            return $response;
        }

        throw new IdentityUnavailable('Identity is unreachable.');
    }

    private function pending(): PendingRequest
    {
        return $this->http->timeout($this->timeout)->connectTimeout($this->connectTimeout)->withoutRedirecting()->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
