<?php

namespace AutoReflex\IdentityConnector\Testing;

use AutoReflex\IdentityConnector\Webhooks\WebhookSignature;
use Carbon\Carbon;
use Firebase\JWT\JWT;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Identity simulé pour les tests d'un produit : trousseau de test, JWKS et `/userinfo` via `Http::fake`.
 * Aucun service Identity n'est nécessaire. Voir le trait `FakesIdentity`.
 */
final class FakeIdentity
{
    /** Secret de webhook des tests : `install()` le configure, `webhook()` signe avec lui. */
    public const WEBHOOK_SECRET = 'test-webhook-secret-0123456789abcdef0123';

    /** @var list<SigningKey> */
    private array $published = [];

    /** @var array<string, array{name: string, email: string, verified: bool, locale: string}> */
    private array $users = [];

    /** @var array<string, true> */
    private array $revoked = [];

    /** @var array<string, array{name: string, slug: string, created_at: string, members: array<string, string>}> */
    private array $organizations = [];

    /** @var array<string, string> secrets des clients de service, par `client_id` */
    private array $serviceClients = [];

    /** @var array<string, array{status: string, scheduled_for: string}> suppressions de compte en cours */
    private array $deletions = [];

    /** @var list<string> comptes dont le produit a accusé l'effacement */
    private array $acknowledged = [];

    private FakeVehicles $vehicleStore;

    private int $exchangeCalls = 0;

    private int $serviceTokenCalls = 0;

    /** @var array<string, mixed>|null */
    private ?array $userInfoOverride = null;

    private bool $down = false;

    private int $userInfoCalls = 0;

    private function __construct(public readonly string $issuer, public readonly string $audience, private SigningKey $key)
    {
        $this->published = [$key];
        $this->vehicleStore = new FakeVehicles(str_replace('-api', '', $audience));
    }

    /**
     * Les véhicules du faux Identity : `$identity->vehicles()->add($id, $ownerUserId, ['identity' => [...]], ['autodonuts' => [...]])`.
     */
    public function vehicles(): FakeVehicles
    {
        return $this->vehicleStore;
    }

    /**
     * Configure le connecteur pour cet Identity simulé et intercepte ses appels HTTP.
     */
    public static function install(string $audience, string $issuer = 'https://identity.test'): self
    {
        $fake = new self($issuer, $audience, SigningKey::generate('test-key-1'));

        Config::set('identity-connector.issuer', $issuer);
        Config::set('identity-connector.audience', $audience);
        Config::set('identity-connector.url', null);
        Config::set('identity-connector.jwks_url', null);
        Config::set('identity-connector.webhooks.secrets', [self::WEBHOOK_SECRET]);

        Http::fake([rtrim($issuer, '/').'/*' => fn (Request $request) => $fake->respond($request)]);

        return $fake;
    }

    /**
     * Déclare une personne connue d'Identity (réponse de `/userinfo`).
     */
    public function user(string $id, string $name = 'Camille Durand', string $email = 'camille@example.test', string $locale = 'fr', bool $emailVerified = true): self
    {
        $this->users[$id] = ['name' => $name, 'email' => $email, 'verified' => $emailVerified, 'locale' => $locale];

        return $this;
    }

    /**
     * Access token d'une personne pour l'API du produit. `$claims` remplace ou ajoute des claims.
     *
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $claims
     */
    public function tokenFor(string $subject, array $scopes = ['profile', 'email'], array $claims = []): string
    {
        return $this->sign([
            'sub' => $subject,
            'client_id' => 'test-client',
            'scope' => implode(' ', $scopes),
            ...$claims,
        ]);
    }

    /**
     * Déclare une organisation Identity et ses membres (`userId => rôle`).
     *
     * @param  array<string, string>  $members
     */
    public function organization(string $id, string $name, array $members, ?string $slug = null): self
    {
        $this->organizations[$id] = [
            'name' => $name,
            'slug' => $slug ?? Str::slug($name),
            'created_at' => Carbon::now()->toIso8601String(),
            'members' => $members,
        ];

        return $this;
    }

    /**
     * Déclare une suppression de compte en cours côté Identity : `pending` (profil à verrouiller) ou
     * `processing` (effacement demandé : le produit efface puis accuse).
     */
    public function deletion(string $userId, string $status = 'pending', ?string $scheduledFor = null): self
    {
        $this->deletions[$userId] = ['status' => $status, 'scheduled_for' => $scheduledFor ?? Carbon::now()->addDays(30)->toIso8601String()];

        return $this;
    }

    /**
     * Comptes dont le produit a accusé l'effacement auprès du faux Identity.
     *
     * @return list<string>
     */
    public function acknowledgedDeletions(): array
    {
        return $this->acknowledged;
    }

    /**
     * Enregistre le client de service de l'API (`client_credentials`) auprès d'Identity.
     */
    public function serviceClient(string $clientId, string $secret): self
    {
        $this->serviceClients[$clientId] = $secret;

        return $this;
    }

    /**
     * Token d'un client de service (`sub` = `client_id`).
     *
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $claims
     */
    public function serviceTokenFor(string $clientId, array $scopes = [], array $claims = []): string
    {
        return $this->tokenFor($clientId, $scopes, ['client_id' => $clientId, ...$claims]);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims): string
    {
        $now = Carbon::now()->getTimestamp();

        return $this->key->sign([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'jti' => (string) Str::ulid(),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 900,
            ...$claims,
        ]);
    }

    /**
     * Fait tourner la clé : les nouveaux tokens sont signés par la nouvelle, l'ancienne reste publiée.
     */
    public function rotateKey(): SigningKey
    {
        $this->key = SigningKey::generate('test-key-'.(count($this->published) + 1));
        $this->published[] = $this->key;

        return $this->key;
    }

    /**
     * Identity refuse désormais le token de cette personne (compte suspendu ou token révoqué).
     */
    public function revoke(string $subject): self
    {
        $this->revoked[$subject] = true;

        return $this;
    }

    /**
     * Identity ne répond plus (JWKS, `/userinfo`…).
     */
    public function goDown(): self
    {
        $this->down = true;

        return $this;
    }

    public function comeBack(): self
    {
        $this->down = false;

        return $this;
    }

    /**
     * Impose la réponse de `/userinfo`, quel que soit le token : pour tester un produit face à une réponse incohérente.
     *
     * @param  array<string, mixed>  $claims
     */
    public function forceUserInfo(array $claims): self
    {
        $this->userInfoOverride = $claims;

        return $this;
    }

    public function exchangeCalls(): int
    {
        return $this->exchangeCalls;
    }

    public function serviceTokenCalls(): int
    {
        return $this->serviceTokenCalls;
    }

    /**
     * Corps et en-têtes d'un webhook d'Identity correctement signé, à poster sur la route du connecteur :
     *
     *     ['body' => $body, 'server' => $server] = $identity->webhook('account.suspended', $userId);
     *     $this->call('POST', '/identity/webhooks', [], [], [], $server, $body)->assertNoContent();
     *
     * `$subjectId` est l'identifiant du compte, de l'organisation pour `organization.deleted`, du véhicule pour `vehicle.*`. `account.deletion_requested`
     * porte en plus `scheduled_for` ; `$data` remplace le contenu si besoin.
     *
     * @param  array<string, string>|null  $data
     * @return array{body: string, server: array<string, string>}
     */
    public function webhook(string $type, string $subjectId, ?string $eventId = null, ?int $timestamp = null, ?array $data = null): array
    {
        $data ??= match ($type) {
            'organization.deleted' => ['organization_id' => $subjectId],
            'vehicle.deleted' => ['vehicle_id' => $subjectId],
            'vehicle.unlinked' => ['vehicle_id' => $subjectId, 'product' => str_replace('-api', '', $this->audience)],
            'account.deletion_requested' => ['user_id' => $subjectId, 'scheduled_for' => Carbon::now('UTC')->addDays(30)->toIso8601String()],
            default => ['user_id' => $subjectId],
        };

        $body = json_encode([
            'id' => $eventId ?? (string) Str::ulid(),
            'type' => $type,
            'version' => 1,
            'occurred_at' => Carbon::now('UTC')->toIso8601String(),
            'data' => $data,
        ], JSON_THROW_ON_ERROR);

        return [
            'body' => $body,
            'server' => [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_IDENTITY_SIGNATURE' => WebhookSignature::header($body, self::WEBHOOK_SECRET, $timestamp ?? Carbon::now()->getTimestamp()),
            ],
        ];
    }

    public function userInfoCalls(): int
    {
        return $this->userInfoCalls;
    }

    /**
     * @return PromiseInterface
     */
    private function respond(Request $request)
    {
        if ($this->down) {
            return Http::response('', 503);
        }

        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/.well-known/jwks.json' => Http::response(
                ['keys' => array_map(fn (SigningKey $key) => $key->jwk(), $this->published)],
                200,
                ['Cache-Control' => 'public, max-age=300'],
            ),
            '/userinfo' => $this->userInfo($request),
            '/oauth/token' => $this->token($request),
            default => $this->api($request),
        };
    }

    /**
     * @return PromiseInterface
     */
    private function token(Request $request)
    {
        $data = $request->data();

        if (($data['grant_type'] ?? null) === 'client_credentials') {
            $this->serviceTokenCalls++;
            $clientId = (string) ($data['client_id'] ?? '');

            if (! isset($this->serviceClients[$clientId]) || $this->serviceClients[$clientId] !== ($data['client_secret'] ?? null)) {
                return Http::response(['error' => 'invalid_client'], 401);
            }

            $scopes = array_values(array_filter(explode(' ', (string) ($data['scope'] ?? ''))));

            if ($scopes === [] || array_diff($scopes, ['vehicles:read', 'accounts:status', 'accounts:deletion']) !== []) {
                return Http::response(['error' => 'invalid_scope'], 400);
            }

            return Http::response(['token_type' => 'Bearer', 'expires_in' => 900, 'access_token' => $this->identityApiToken($clientId, $scopes, $clientId)]);
        }

        if (($data['grant_type'] ?? null) === 'urn:ietf:params:oauth:grant-type:token-exchange' && ($data['audience'] ?? null) === 'identity-api') {
            $this->exchangeCalls++;
            $subject = $this->decode((string) ($data['subject_token'] ?? ''));
            $person = $subject['sub'] ?? null;

            if (! is_string($person) || ! isset($this->users[$person]) || isset($this->revoked[$person])
                || ($subject['aud'] ?? null) !== $this->audience || ($subject['exp'] ?? 0) <= Carbon::now()->getTimestamp()
                || ($subject['client_id'] ?? null) !== ($data['client_id'] ?? null)) {
                return Http::response(['error' => 'invalid_grant'], 400);
            }

            return Http::response(['token_type' => 'Bearer', 'expires_in' => 900, 'access_token' => $this->identityApiToken($person, array_filter(['vehicles:read', 'vehicles:write', 'organizations:read', in_array('vehicles:sensitive', explode(' ', (string) ($subject['scope'] ?? '')), true) ? 'vehicles:sensitive' : null]), (string) $subject['client_id'])]);
        }

        return Http::response(['error' => 'unsupported_grant_type'], 400);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function identityApiToken(string $subject, array $scopes, string $clientId): string
    {
        return $this->tokenFor($subject, $scopes, ['aud' => 'identity-api', 'client_id' => $clientId]);
    }

    /**
     * L'API `identity-api` v1 (AR-054), avec les mêmes formes et les mêmes refus que le vrai service.
     *
     * @return PromiseInterface
     */
    private function api(Request $request)
    {
        $token = $this->decode(Str::after($request->header('Authorization')[0] ?? '', 'Bearer '));

        if (($token['aud'] ?? null) !== 'identity-api' || ($token['exp'] ?? 0) <= Carbon::now()->getTimestamp()
            || (isset($token['sub']) && isset($this->revoked[$token['sub']]))) {
            return Http::response(['error' => 'invalid_token'], 401);
        }

        $scopes = explode(' ', (string) ($token['scope'] ?? ''));
        $isService = ($token['sub'] ?? null) === ($token['client_id'] ?? '');
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $person = (string) ($token['sub'] ?? '');

        if ($path === '/api/v1/vehicles' || str_starts_with($path, '/api/v1/vehicles/')) {
            return $this->vehicleStore->handle($request, $token, $path);
        }

        if ($path === '/api/v1/organizations' || str_starts_with($path, '/api/v1/organizations/')) {
            if ($isService || ! in_array('organizations:read', $scopes, true)) {
                return Http::response(['error' => $isService ? 'forbidden' : 'insufficient_scope'], 403);
            }

            $id = str_starts_with($path, '/api/v1/organizations/') ? substr($path, strlen('/api/v1/organizations/')) : null;

            return $id === null ? $this->organizationList($person) : $this->organizationDetail($person, rawurldecode($id));
        }

        if (preg_match('#^/api/v1/accounts/([^/]+)/deletion/ack$#', $path, $matches) === 1) {
            if (! $isService || ! in_array('accounts:deletion', $scopes, true)) {
                return Http::response(['error' => $isService ? 'insufficient_scope' : 'forbidden'], 403);
            }

            $id = rawurldecode($matches[1]);

            if (($this->deletions[$id]['status'] ?? null) !== 'processing') {
                return Http::response(['error' => 'not_found'], 404);
            }

            $this->acknowledged[] = $id;

            return Http::response(['status' => 'acknowledged']);
        }

        if (preg_match('#^/api/v1/accounts/([^/]+)/status$#', $path, $matches) === 1) {
            if (! $isService || ! in_array('accounts:status', $scopes, true)) {
                return Http::response(['error' => $isService ? 'insufficient_scope' : 'forbidden'], 403);
            }

            $id = rawurldecode($matches[1]);

            return Http::response([
                'id' => $id,
                'exists' => isset($this->users[$id]),
                'suspended' => isset($this->revoked[$id]),
                'deletion' => $this->deletions[$id]['status'] ?? null,
                'deletion_scheduled_for' => $this->deletions[$id]['scheduled_for'] ?? null,
            ]);
        }

        return Http::response(['error' => 'not_found'], 404);
    }

    /**
     * @return PromiseInterface
     */
    private function organizationList(string $person)
    {
        $mine = array_filter($this->organizations, fn (array $organization) => isset($organization['members'][$person]));
        uasort($mine, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return Http::response(['data' => array_map(
            fn (string $id, array $organization) => $this->summary($id, $organization, $person),
            array_keys($mine),
            $mine,
        )]);
    }

    /**
     * @return PromiseInterface
     */
    private function organizationDetail(string $person, string $id)
    {
        $organization = $this->organizations[$id] ?? null;

        if ($organization === null || ! isset($organization['members'][$person])) {
            return Http::response(['message' => 'Not Found'], 404);
        }

        return Http::response(['data' => $this->summary($id, $organization, $person) + [
            'created_at' => $organization['created_at'],
            'members' => array_map(
                fn (string $userId, string $role) => ['user_id' => $userId, 'name' => $this->users[$userId]['name'] ?? null, 'role' => $role],
                array_keys($organization['members']),
                $organization['members'],
            ),
        ]]);
    }

    /**
     * @param  array{name: string, slug: string, created_at: string, members: array<string, string>}  $organization
     * @return array{id: string, name: string, slug: string, role: string}
     */
    private function summary(string $id, array $organization, string $person): array
    {
        return ['id' => $id, 'name' => $organization['name'], 'slug' => $organization['slug'], 'role' => $organization['members'][$person]];
    }

    /**
     * @return PromiseInterface
     */
    private function userInfo(Request $request)
    {
        $this->userInfoCalls++;

        if ($this->userInfoOverride !== null) {
            return Http::response($this->userInfoOverride);
        }

        $token = $this->decode(Str::after($request->header('Authorization')[0] ?? '', 'Bearer '));
        $subject = $token['sub'] ?? null;

        if (! is_string($subject) || isset($this->revoked[$subject]) || ! isset($this->users[$subject])) {
            return Http::response(['error' => 'invalid_token'], 401);
        }

        $user = $this->users[$subject];
        $scopes = explode(' ', (string) ($token['scope'] ?? ''));
        $claims = ['sub' => $subject];

        if (in_array('profile', $scopes, true)) {
            $claims += ['name' => $user['name'], 'locale' => $user['locale']];
        }

        if (in_array('email', $scopes, true)) {
            $claims += ['email' => $user['email'], 'email_verified' => $user['verified']];
        }

        return Http::response($claims);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $jwt): array
    {
        $segments = explode('.', $jwt);

        return count($segments) === 3 ? (array) json_decode(JWT::urlsafeB64Decode($segments[1]), true) : [];
    }
}
