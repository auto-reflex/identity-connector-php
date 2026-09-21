<?php

namespace AutoGteck\IdentityConnector\Web;

use AutoGteck\IdentityConnector\Client\IdentityClient;
use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\IdentityUnavailable;

/**
 * Le client OAuth d'une application web (AR-072) : code d'autorisation + PKCE S256, client CONFIDENTIEL (le secret ne quitte jamais
 * le serveur). Les appels à Identity passent par `IdentityClient` : mêmes délais courts, mêmes erreurs typées.
 */
final class WebLoginClient
{
    public function __construct(
        private readonly IdentityClient $client,
        private readonly string $issuer,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $scope,
    ) {}

    /**
     * L'adresse d'Identity où envoyer le navigateur. `prompt=login` force la saisie du mot de passe : après une déconnexion,
     * la session d'Identity reste ouverte, sans quoi le retour serait automatique.
     */
    public function authorizationUrl(string $redirectUri, string $state, string $challenge, bool $forceLogin = false): string
    {
        return rtrim($this->issuer, '/').'/oauth/authorize?'.http_build_query(array_filter([
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $this->scope,
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'prompt' => $forceLogin ? 'login' : null,
        ]), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): WebTokens
    {
        return $this->tokens([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * Un refresh token ne sert qu'une fois : Identity en rend un nouveau, et révoque l'ancien access token.
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function refresh(string $refreshToken): WebTokens
    {
        return $this->tokens(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function revoke(string $token): void
    {
        $this->client->oauthRevoke(['token' => $token, 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
    }

    /**
     * @param  array<string, string>  $form
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    private function tokens(array $form): WebTokens
    {
        $body = $this->client->oauthToken($form + ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret]);
        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;

        if (! is_string($access) || $access === '' || ! is_string($refresh) || $refresh === '') {
            throw new IdentityUnavailable('Identity returned an unusable token response.');
        }

        return new WebTokens($access, $refresh);
    }
}
