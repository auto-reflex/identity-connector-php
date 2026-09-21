<?php

namespace AutoGteck\IdentityConnector\Testing;

use AutoGteck\IdentityConnector\Jwt\JwtVerifier;
use AutoGteck\IdentityConnector\Web\WebConnection;
use AutoGteck\IdentityConnector\Web\WebSession;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * La partie « connexion d'une application web » du faux Identity (AR-072) : codes d'autorisation avec PKCE, refresh tokens à usage
 * unique (comme le vrai Identity), révocation. Voir `FakeIdentity::web()`.
 *
 *     $login = $this->get('/admin/auth/redirect');                         // le produit envoie vers Identity
 *     $back = $identity->web()->approve($login->headers->get('Location'), $userId);   // la personne s'authentifie
 *     $this->get($back)->assertRedirect('/admin');                          // le produit reçoit le code
 */
final class FakeWebGrants
{
    /** @var array<string, array{user: string, challenge: string, redirect_uri: string, scopes: list<string>}> */
    private array $codes = [];

    /** @var array<string, array{user: string, scopes: list<string>, access: string}> refresh tokens encore valables */
    private array $refreshTokens = [];

    /** @var array<string, list<string>> rôles remis à la prochaine émission, par personne */
    private array $roles = [];

    /** @var array<string, string> access token => refresh token émis avec lui */
    private array $pairs = [];

    /** @var list<string> */
    private array $revoked = [];

    /** @var array<string, string> */
    private array $lastAuthorization = [];

    private int $refreshCalls = 0;

    public function __construct(private readonly FakeIdentity $identity) {}

    /**
     * La personne s'authentifie chez Identity et accepte : rend l'adresse de retour (code + state) que le navigateur suivrait.
     * `$authorizationUrl` est l'adresse vers laquelle le produit a redirigé ; ses paramètres sont contrôlés comme le ferait Identity.
     *
     * @param  list<string>|null  $roles  rôles délivrés à cette personne (défaut : `admin`, ou ceux déjà fixés par `roles()`)
     */
    public function approve(string $authorizationUrl, string $userId, ?array $roles = null): string
    {
        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        $this->lastAuthorization = $query;

        foreach (['client_id', 'redirect_uri', 'state', 'code_challenge'] as $required) {
            if (! isset($query[$required]) || $query[$required] === '') {
                throw new \InvalidArgumentException("The authorization request has no {$required}.");
            }
        }

        if (($query['response_type'] ?? null) !== 'code' || ($query['code_challenge_method'] ?? null) !== 'S256' || $query['client_id'] !== $this->clientId()) {
            throw new \InvalidArgumentException('The authorization request must be `response_type=code`, PKCE S256, for the configured web client.');
        }

        if ($roles !== null || ! isset($this->roles[$userId])) {
            $this->roles[$userId] = $roles ?? ['admin'];
        }

        $this->codes[$code = Str::random(32)] = [
            'user' => $userId,
            'challenge' => $query['code_challenge'],
            'redirect_uri' => $query['redirect_uri'],
            'scopes' => array_values(array_filter(explode(' ', $query['scope'] ?? ''))),
        ];

        return $query['redirect_uri'].'?'.http_build_query(['code' => $code, 'state' => $query['state']]);
    }

    /**
     * Les rôles que recevra la personne à sa prochaine émission (connexion ou refresh) : `[]` = rôle retiré.
     *
     * @param  list<string>  $roles
     */
    public function roles(string $userId, array $roles): self
    {
        $this->roles[$userId] = $roles;

        return $this;
    }

    /**
     * Pose directement une connexion ouverte, sans jouer le parcours : rend l'identifiant à mettre en session
     * (`WebSession::SESSION_KEY`). Le plus simple est `FakesIdentity::actingAsWebIdentity()`.
     *
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $claims  remplace des claims du token (`exp`, `scope`…)
     */
    public function signIn(string $userId, array $roles = ['admin'], string $name = 'Camille Durand', array $claims = []): string
    {
        $this->identity->user($userId, $name);
        $this->roles[$userId] = $roles;
        $tokens = $this->issue($userId, ['profile', $this->productScope()], $claims);
        $verified = app(JwtVerifier::class)->verify($tokens['access_token']);
        $sessions = app(WebSession::class);
        $connection = new WebConnection($sessions->newId(), $tokens['access_token'], $tokens['refresh_token'], $name, $verified);
        $sessions->store($connection);

        return $connection->id;
    }

    public function refreshCalls(): int
    {
        return $this->refreshCalls;
    }

    /**
     * @return list<string> les tokens dont la révocation a été demandée
     */
    public function revokedTokens(): array
    {
        return $this->revoked;
    }

    /**
     * Les paramètres de la dernière demande d'autorisation lue par `approve()` (`prompt`, `scope`…).
     *
     * @return array<string, string>
     */
    public function lastAuthorization(): array
    {
        return $this->lastAuthorization;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return PromiseInterface
     */
    public function token(array $data)
    {
        if (($data['client_id'] ?? null) !== $this->clientId() || ($data['client_secret'] ?? null) !== $this->clientSecret()) {
            return Http::response(['error' => 'invalid_client'], 401);
        }

        return ($data['grant_type'] ?? null) === 'authorization_code' ? $this->exchange($data) : $this->refresh($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return PromiseInterface
     */
    public function revoke(array $data)
    {
        if (($data['client_id'] ?? null) !== $this->clientId() || ($data['client_secret'] ?? null) !== $this->clientSecret()) {
            return Http::response(['error' => 'invalid_client'], 401);
        }

        $token = (string) ($data['token'] ?? '');
        $this->revoked[] = $token;

        // Comme Identity : révoquer un access token révoque aussi les refresh tokens qui s'y rattachent.
        if (isset($this->pairs[$token])) {
            unset($this->refreshTokens[$this->pairs[$token]]);
        }

        return Http::response([]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return PromiseInterface
     */
    private function exchange(array $data)
    {
        $grant = $this->codes[(string) ($data['code'] ?? '')] ?? null;
        unset($this->codes[(string) ($data['code'] ?? '')]);

        $verifier = (string) ($data['code_verifier'] ?? '');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if ($grant === null || $grant['redirect_uri'] !== ($data['redirect_uri'] ?? null) || ! hash_equals($grant['challenge'], $challenge)) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        return Http::response($this->body($this->issue($grant['user'], $grant['scopes'])));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return PromiseInterface
     */
    private function refresh(array $data)
    {
        $this->refreshCalls++;
        $presented = (string) ($data['refresh_token'] ?? '');
        $grant = $this->refreshTokens[$presented] ?? null;

        // Un refresh token ne sert qu'une fois, et un compte suspendu n'en obtient plus.
        unset($this->refreshTokens[$presented]);

        if ($grant === null || $this->identity->isRevoked($grant['user'])) {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        return Http::response($this->body($this->issue($grant['user'], $grant['scopes'])));
    }

    /**
     * @param  list<string>  $scopes
     * @param  array<string, mixed>  $claims
     * @return array{access_token: string, refresh_token: string}
     */
    private function issue(string $userId, array $scopes, array $claims = []): array
    {
        $roles = $this->roles[$userId] ?? [];
        $access = $this->identity->tokenFor($userId, $scopes, ['client_id' => $this->clientId(), ...($roles === [] ? [] : ['roles' => $roles]), ...$claims]);
        $refresh = 'rt-'.Str::random(40);

        $this->refreshTokens[$refresh] = ['user' => $userId, 'scopes' => $scopes, 'access' => $access];
        $this->pairs[$access] = $refresh;

        return ['access_token' => $access, 'refresh_token' => $refresh];
    }

    /**
     * @param  array{access_token: string, refresh_token: string}  $tokens
     * @return array<string, mixed>
     */
    private function body(array $tokens): array
    {
        return ['token_type' => 'Bearer', 'expires_in' => 900] + $tokens;
    }

    private function clientId(): string
    {
        return (string) Config::get('identity-connector.web.client_id');
    }

    private function clientSecret(): string
    {
        return (string) Config::get('identity-connector.web.client_secret');
    }

    private function productScope(): string
    {
        return (string) Config::get('identity-connector.web.scope');
    }
}
