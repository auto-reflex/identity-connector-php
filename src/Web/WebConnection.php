<?php

namespace AutoGteck\IdentityConnector\Web;

use AutoGteck\IdentityConnector\Jwt\VerifiedToken;

/**
 * Une connexion web : les jetons d'Identity et le token d'accès déjà vérifié. Elle vit chiffrée dans le cache, jamais dans la
 * session (voir `WebSession`).
 */
final readonly class WebConnection
{
    public function __construct(
        public string $id,
        public string $accessToken,
        public string $refreshToken,
        public ?string $name,
        public VerifiedToken $token,
    ) {}

    public function identity(): WebIdentity
    {
        return new WebIdentity($this->token->subject, $this->name, $this->token->roles, $this->token->expiresAt);
    }

    public function withTokens(WebTokens $tokens, VerifiedToken $verified): self
    {
        return new self($this->id, $tokens->accessToken, $tokens->refreshToken, $this->name, $verified);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'name' => $this->name,
            'token' => [
                'sub' => $this->token->subject,
                'aud' => $this->token->audience,
                'client_id' => $this->token->clientId,
                'jti' => $this->token->tokenId,
                'scopes' => $this->token->scopes,
                'exp' => $this->token->expiresAt,
                'roles' => $this->token->roles,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $token = $data['token'] ?? null;

        if (! is_string($data['id'] ?? null) || ! is_string($data['access_token'] ?? null) || ! is_string($data['refresh_token'] ?? null)
            || ! is_array($token) || ! is_string($token['sub'] ?? null) || ! is_string($token['aud'] ?? null) || ! is_string($token['client_id'] ?? null)
            || ! is_string($token['jti'] ?? null) || ! is_int($token['exp'] ?? null) || ! is_array($token['scopes'] ?? null) || ! is_array($token['roles'] ?? null)) {
            return null;
        }

        return new self(
            $data['id'],
            $data['access_token'],
            $data['refresh_token'],
            is_string($data['name'] ?? null) ? $data['name'] : null,
            new VerifiedToken(
                subject: $token['sub'],
                audience: $token['aud'],
                clientId: $token['client_id'],
                tokenId: $token['jti'],
                scopes: array_values(array_filter($token['scopes'], 'is_string')),
                expiresAt: $token['exp'],
                roles: array_values(array_filter($token['roles'], 'is_string')),
            ),
        );
    }
}
