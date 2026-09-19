<?php

namespace AutoReflex\IdentityConnector\Testing;

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
    /** @var list<SigningKey> */
    private array $published = [];

    /** @var array<string, array{name: string, email: string, verified: bool, locale: string}> */
    private array $users = [];

    /** @var array<string, true> */
    private array $revoked = [];

    /** @var array<string, mixed>|null */
    private ?array $userInfoOverride = null;

    private bool $down = false;

    private int $userInfoCalls = 0;

    private function __construct(public readonly string $issuer, public readonly string $audience, private SigningKey $key)
    {
        $this->published = [$key];
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
            default => Http::response(['error' => 'not_found'], 404),
        };
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
