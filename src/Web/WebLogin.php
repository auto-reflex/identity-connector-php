<?php

namespace AutoGteck\IdentityConnector\Web;

use AutoGteck\IdentityConnector\Client\IdentityClient;
use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\IdentityUnavailable;
use AutoGteck\IdentityConnector\Jwt\InvalidAccessToken;
use AutoGteck\IdentityConnector\Jwt\JwtVerifier;
use AutoGteck\IdentityConnector\Jwt\KeySetUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Le parcours de connexion d'une application web (AR-072) : envoi vers Identity, retour, déconnexion, renouvellement.
 * Une erreur de connexion revient sur la page de connexion de l'application avec le code en session flash `identity_error` :
 * `access_denied`, `invalid_state`, `unavailable`, `insufficient_scope`, `invalid_token`, `failed`, `session_expired`.
 */
final class WebLogin
{
    public const LOGIN_KEY = 'identity_web_login';

    /** Posé à la déconnexion : la session d'Identity reste ouverte, la prochaine connexion doit redemander le mot de passe. */
    public const FRESH_KEY = 'identity_web_fresh';

    public const ERROR_KEY = 'identity_error';

    public function __construct(
        private readonly WebLoginClient $client,
        private readonly IdentityClient $identity,
        private readonly JwtVerifier $verifier,
        private readonly WebSession $sessions,
        private readonly string $productScope,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $session = $request->session();

        if ($this->sessions->current($session) !== null) {
            return redirect()->intended($this->home());
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(40);
        $redirectUri = $this->callbackUri();

        $session->put(self::LOGIN_KEY, ['state' => $state, 'verifier' => $verifier, 'redirect_uri' => $redirectUri]);
        $fresh = $request->boolean('fresh') || (bool) $session->pull(self::FRESH_KEY, false);

        return redirect()->away($this->client->authorizationUrl($redirectUri, $state, $challenge, $fresh));
    }

    public function callback(Request $request): RedirectResponse
    {
        $session = $request->session();
        $pending = $session->pull(self::LOGIN_KEY);
        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($pending['state'] ?? null) || ! is_string($pending['verifier'] ?? null) || ! is_string($pending['redirect_uri'] ?? null)
            || ! is_string($state) || ! hash_equals($pending['state'], $state)) {
            return $this->failure('invalid_state');
        }

        if ($request->query('error') !== null) {
            return $this->failure($request->query('error') === 'access_denied' ? 'access_denied' : 'failed');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->failure('failed');
        }

        try {
            $tokens = $this->client->exchangeCode($code, $pending['verifier'], $pending['redirect_uri']);
        } catch (IdentityUnavailable) {
            return $this->failure('unavailable');
        } catch (IdentityRejected) {
            return $this->failure('failed');
        }

        try {
            $verified = $this->verifier->verify($tokens->accessToken);
        } catch (InvalidAccessToken) {
            $this->revokeQuietly($tokens->accessToken);

            return $this->failure('invalid_token');
        } catch (KeySetUnavailable) {
            return $this->failure('unavailable');
        }

        if ($verified->isClientToken() || ! $verified->hasScope($this->productScope)) {
            $this->revokeQuietly($tokens->accessToken);

            return $this->failure('insufficient_scope');
        }

        // Session neuve avant d'y attacher la connexion : un identifiant de session connu d'avant la connexion ne sert plus.
        $session->regenerate();
        $this->sessions->begin($session, new WebConnection($this->sessions->newId(), $tokens->accessToken, $tokens->refreshToken, $this->nameOf($tokens->accessToken), $verified));

        return redirect()->intended($this->home());
    }

    public function logout(Request $request): RedirectResponse
    {
        $session = $request->session();
        $connection = $this->sessions->current($session);

        if ($connection !== null) {
            $this->revokeQuietly($connection->accessToken);
        }

        $this->sessions->forget($session);
        $session->invalidate();
        $session->regenerateToken();
        $session->put(self::FRESH_KEY, true);

        return redirect($this->loginPage());
    }

    /**
     * Le refresh d'une connexion : nouveaux jetons, vérifiés comme à la connexion. Les rôles sont ceux d'AUJOURD'HUI (Identity les
     * recalcule à chaque émission : un rôle retiré disparaît ici).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     * @throws InvalidAccessToken
     * @throws KeySetUnavailable
     */
    public function renew(WebConnection $connection): WebConnection
    {
        $tokens = $this->client->refresh($connection->refreshToken);
        $verified = $this->verifier->verify($tokens->accessToken);

        if ($verified->subject !== $connection->token->subject || ! $verified->hasScope($this->productScope)) {
            throw new InvalidAccessToken('The refreshed token does not belong to the same person or lost the product scope.');
        }

        return $connection->withTokens($tokens, $verified);
    }

    /**
     * Renvoie vers la connexion : la page voulue est mémorisée pour le retour ; une requête JSON reçoit 401.
     */
    public function toLogin(Request $request, ?string $error = null): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => $error ?? 'unauthenticated'], 401);
        }

        $redirect = redirect()->guest($this->loginPage());

        return $error === null ? $redirect : $redirect->with(self::ERROR_KEY, $error);
    }

    private function failure(string $error): RedirectResponse
    {
        return redirect($this->loginPage())->with(self::ERROR_KEY, $error);
    }

    private function nameOf(string $accessToken): ?string
    {
        try {
            return $this->identity->userInfo($accessToken)->name;
        } catch (IdentityUnavailable|IdentityRejected) {
            // Le nom n'est que de l'affichage : il ne doit jamais empêcher la connexion.
            return null;
        }
    }

    private function revokeQuietly(string $token): void
    {
        try {
            $this->client->revoke($token);
        } catch (IdentityUnavailable|IdentityRejected) {
            Log::warning('identity-connector: the web session token could not be revoked at Identity.');
        }
    }

    private function callbackUri(): string
    {
        $configured = config('identity-connector.web.redirect_uri');

        return is_string($configured) && $configured !== '' ? $configured : route(WebRoutes::name(WebRoutes::CALLBACK));
    }

    private function loginPage(): string
    {
        $configured = config('identity-connector.web.login_page');

        return is_string($configured) && $configured !== '' ? $this->target($configured) : route(WebRoutes::name(WebRoutes::LOGIN));
    }

    private function home(): string
    {
        $configured = config('identity-connector.web.home');

        return $this->target(is_string($configured) && $configured !== '' ? $configured : '/');
    }

    /**
     * Un nom de route ou un chemin.
     */
    private function target(string $nameOrPath): string
    {
        return Route::has($nameOrPath) ? route($nameOrPath) : url($nameOrPath);
    }
}
