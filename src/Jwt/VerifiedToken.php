<?php

namespace AutoReflex\IdentityConnector\Jwt;

/**
 * Claims d'un access token dont la signature, l'émetteur, l'audience et l'expiration ont été vérifiés.
 */
final readonly class VerifiedToken
{
    /**
     * @param  list<string>  $scopes
     * @param  list<string>  $roles  rôles d'équipe de la personne dans ce produit (claim `roles`, AR-066)
     */
    public function __construct(
        public string $subject,
        public string $audience,
        public string $clientId,
        public string $tokenId,
        public array $scopes,
        public int $expiresAt,
        public array $roles = [],
    ) {}

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Un token sans personne (client_credentials) a pour `sub` l'identifiant du client.
     */
    public function isClientToken(): bool
    {
        return $this->subject === $this->clientId;
    }
}
