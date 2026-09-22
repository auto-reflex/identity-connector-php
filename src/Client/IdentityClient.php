<?php

namespace AutoGteck\IdentityConnector\Client;

use AutoGteck\IdentityConnector\Profiles\IdentityUser;
use Carbon\CarbonImmutable;
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

        return new AccountStatus(
            (string) $data['id'],
            (bool) $data['exists'],
            (bool) $data['suspended'],
            isset($data['deletion']) ? (string) $data['deletion'] : null,
            isset($data['deletion_scheduled_for']) ? CarbonImmutable::parse((string) $data['deletion_scheduled_for']) : null,
        );
    }

    /**
     * Accuse à Identity l'effacement des données locales d'un compte, après `AccountDeletionDue` (AR-056).
     * Idempotent : sans risque à rejouer. `false` s'il n'y a pas d'effacement en cours pour ce produit et ce compte.
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function acknowledgeDeletion(string $userId): bool
    {
        try {
            $this->asService('accounts:deletion', '/api/v1/accounts/'.rawurlencode($userId).'/deletion/ack', 'post');
        } catch (IdentityRejected $rejected) {
            if ($rejected->status === 404) {
                return false;
            }

            throw $rejected;
        }

        return true;
    }

    /**
     * Crée l'organisation d'un professionnel (`POST /api/v1/provisioning/organizations`, scope `organizations:provision`, AR-070).
     * `$reference` est l'identifiant de la demande chez ce produit : rappeler avec la même référence ne crée rien de plus.
     *
     * Le propriétaire se nomme de **l'une** des deux façons (jamais les deux, jamais aucune : `InvalidArgumentException`) :
     *  - `$ownerUserId` (AR-076) : un compte que ce produit connaît déjà, dont l'email est vérifié. Il devient `owner` tout de suite,
     *    sans invitation ni email : l'état est `active` et aucun événement `OrganizationOwnerJoined` n'est émis (la réponse suffit) ;
     *  - `$ownerEmail` (AR-070) : Identity invite cette adresse ; état `pending`, puis `OrganizationOwnerJoined` à l'acceptation.
     *    Rappeler renvoie un nouveau lien tant que le propriétaire n'a pas rejoint.
     * Rappeler avec `$ownerUserId` une organisation encore `pending` lui donne la propriété et révoque l'invitation.
     *
     * `$legalSiret` est obligatoire (AR-075) : Identity le vérifie auprès de Sirene (établissement existant et actif) avant de créer
     * quoi que ce soit ; rappeler avec la même référence ne relance pas la vérification.
     *
     * @throws \InvalidArgumentException si `$ownerEmail` et `$ownerUserId` sont tous deux absents ou tous deux donnés
     * @throws RegistryUnavailable Sirene ne répond pas : rien n'a été créé, réessayer plus tard
     * @throws IdentityUnavailable
     * @throws LegalIdentityRejected SIRET refusé (`->error` : `siret_invalid`, `siret_not_found`, `siret_inactive`, `siret_taken`) : erreur de formulaire
     * @throws ProvisioningRejected `owner_unknown` (compte inconnu ou email non vérifié) ou `reference_conflict` (référence d'un autre propriétaire)
     * @throws IdentityRejected dont 422 si les autres données sont invalides (`$rejected->body['errors']`)
     */
    public function provisionOrganization(string $reference, string $organizationName, string $legalSiret, ?string $ownerEmail = null, ?string $ownerUserId = null, ?string $locale = null): ProvisionedOrganization
    {
        if (($ownerEmail === null) === ($ownerUserId === null)) {
            throw new \InvalidArgumentException('Give the owner as either an email (invitation) or an Identity account (ownerUserId), not both and not none.');
        }

        try {
            // Idempotent par référence : une reprise sur panne réseau est sans risque.
            $data = $this->serviceRequest('POST', 'organizations:provision', '/api/v1/provisioning/organizations', [
                'json' => array_filter([
                    'reference' => $reference,
                    'email' => $ownerEmail,
                    'owner_user_id' => $ownerUserId,
                    'organization_name' => $organizationName,
                    'legal' => ['siret' => $legalSiret],
                    'locale' => $locale,
                ], fn ($value) => $value !== null),
            ], retry: true)->json('data');
        } catch (IdentityRejected $rejected) {
            if (in_array($rejected->error, LegalIdentityRejected::CODES, true)) {
                throw new LegalIdentityRejected($rejected->status, $rejected->getMessage(), $rejected->error, $rejected->body);
            }

            if (in_array($rejected->error, ProvisioningRejected::CODES, true)) {
                throw new ProvisioningRejected($rejected->status, $rejected->getMessage(), $rejected->error, $rejected->body);
            }

            throw $rejected;
        }

        if (! is_array($data) || ! isset($data['organization_id'], $data['slug'], $data['reference'], $data['state'])) {
            throw new IdentityUnavailable('Identity returned an unusable provisioning response.');
        }

        return ProvisionedOrganization::fromArray($data);
    }

    /**
     * Rattache une organisation que la personne possède déjà à ce produit, sous cette référence (AR-079), au lieu de la créer :
     * le SIRET appartient à l'organisation, pas au produit (AR-075), donc `provisionOrganization()` avec ce SIRET échouerait en
     * `siret_taken` si la personne a déjà une organisation vérifiée à ce SIRET (créée directement chez Identity, ou par un autre
     * produit). Rien n'est créé ni revérifié : Identity enregistre seulement le lien, après avoir vérifié que `$ownerUserId` est
     * owner ou admin de cette organisation. Idempotent par référence, comme `provisionOrganization()`.
     *
     * @throws IdentityUnavailable
     * @throws ProvisioningRejected `not_a_member` (403, la personne n'est ni owner ni admin) ou `already_provisioned` (409,
     *                              l'organisation a déjà une référence, pour un autre produit ou une autre référence)
     * @throws IdentityRejected 404 si l'organisation n'existe pas
     */
    public function adoptOrganization(string $reference, string $organizationId, string $ownerUserId): ProvisionedOrganization
    {
        try {
            $data = $this->serviceRequest('POST', 'organizations:provision', '/api/v1/provisioning/organizations', [
                'json' => ['reference' => $reference, 'organization_id' => $organizationId, 'owner_user_id' => $ownerUserId],
            ], retry: true)->json('data');
        } catch (IdentityRejected $rejected) {
            if (in_array($rejected->error, ProvisioningRejected::CODES, true)) {
                throw new ProvisioningRejected($rejected->status, $rejected->getMessage(), $rejected->error, $rejected->body);
            }

            throw $rejected;
        }

        if (! is_array($data) || ! isset($data['organization_id'], $data['slug'], $data['reference'], $data['state'])) {
            throw new IdentityUnavailable('Identity returned an unusable provisioning response.');
        }

        return ProvisionedOrganization::fromArray($data);
    }

    /**
     * État d'une organisation provisionnée par ce produit ; `null` si la référence est inconnue.
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function provisionedOrganization(string $reference): ?ProvisionedOrganization
    {
        try {
            $data = $this->serviceRequest('GET', 'organizations:provision', '/api/v1/provisioning/organizations/'.rawurlencode($reference))->json('data');
        } catch (IdentityRejected $rejected) {
            if ($rejected->status === 404) {
                return null;
            }

            throw $rejected;
        }

        return is_array($data) && isset($data['organization_id'], $data['slug'], $data['reference'], $data['state']) ? ProvisionedOrganization::fromArray($data) : null;
    }

    /**
     * Appel du point d'accès `/oauth/token` avec un formulaire complet (code d'autorisation, refresh d'un client web, AR-072).
     * Jamais rejoué : un code ou un refresh token ne sert qu'une fois.
     *
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function oauthToken(array $form): array
    {
        $body = $this->send(fn (PendingRequest $request) => $request->asForm()->post($this->url('/oauth/token'), $form), retry: false)->json();

        if (! is_array($body)) {
            throw new IdentityUnavailable('Identity returned an unusable token response.');
        }

        return $body;
    }

    /**
     * Révoque un token (RFC 7009 : l'access token et les refresh tokens qui s'y rattachent). Identity répond 200 même pour un
     * token inconnu.
     *
     * @param  array<string, string>  $form  `token`, `client_id`, `client_secret`
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function oauthRevoke(array $form): void
    {
        $this->send(fn (PendingRequest $request) => $request->asForm()->post($this->url('/oauth/revoke'), $form), retry: false);
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
     * Appel de l'API Identity avec le token `identity-api` de la personne. Un 401 (token en cache révoqué entre-temps) provoque un
     * seul nouvel échange. Reprise sur les seules requêtes idempotentes (GET, PUT, DELETE).
     *
     * @param  array<string, mixed>  $options  `query`, `json`
     * @param  array<string, string>  $headers
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function personRequest(string $method, string $productToken, string $path, array $options = [], array $headers = [], ?bool $retry = null): Response
    {
        $call = fn (string $token): Response => $this->send(
            fn (PendingRequest $request) => $request->withToken($token)->withHeaders($headers)->send(strtoupper($method), $this->url($path), $options),
            retry: $retry ?? in_array(strtoupper($method), ['GET', 'PUT', 'DELETE'], true),
        );

        try {
            return $call($this->identityToken($productToken));
        } catch (IdentityRejected $rejected) {
            if ($rejected->status !== 401) {
                throw $rejected;
            }
        }

        return $call($this->identityToken($productToken, fresh: true));
    }

    /**
     * Appel de l'API Identity avec le token de service (`client_credentials`) du produit, pour les scopes demandés.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function serviceRequest(string $method, string $scope, string $path, array $options = [], array $headers = [], ?bool $retry = null): Response
    {
        $call = fn (string $token): Response => $this->send(
            fn (PendingRequest $request) => $request->withToken($token)->withHeaders($headers)->send(strtoupper($method), $this->url($path), $options),
            retry: $retry ?? in_array(strtoupper($method), ['GET', 'PUT', 'DELETE'], true),
        );

        try {
            return $call($this->serviceToken($scope));
        } catch (IdentityRejected $rejected) {
            if ($rejected->status !== 401) {
                throw $rejected;
            }
        }

        return $call($this->serviceToken($scope, fresh: true));
    }

    private function asPerson(string $productToken, string $path): Response
    {
        return $this->personRequest('GET', $productToken, $path);
    }

    private function asService(string $scope, string $path, string $method = 'get'): Response
    {
        // Les appels de service de ce client sont idempotents (statut, accusé) : une reprise sur panne réseau est sans risque.
        return $this->serviceRequest($method, $scope, $path, retry: true);
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
    /**
     * Un claim du token produit, sans le vérifier (il l'est déjà par le connecteur, et Identity le revérifie).
     */
    public function claimOf(string $jwt, string $claim): string
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

                // Sirene est en panne (AR-075) : ce n'est pas Identity qui l'est, la personne peut réessayer dans un instant.
                if ($response->status() === 503 && $response->json('error') === 'registry_unavailable') {
                    throw new RegistryUnavailable('The company registry is unavailable.');
                }

                throw new IdentityUnavailable("Identity answered HTTP {$response->status()}.");
            }

            // Tout ce qui n'est pas un succès (dont une redirection, jamais suivie) est un refus.
            if (! $response->successful()) {
                $body = $response->json();

                throw new IdentityRejected($response->status(), error: is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null, body: is_array($body) ? $body : []);
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
