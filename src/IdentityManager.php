<?php

namespace AutoReflex\IdentityConnector;

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\IdentityUnavailable;
use AutoReflex\IdentityConnector\Client\Organization;
use AutoReflex\IdentityConnector\Http\Middleware\AuthenticateIdentity;
use AutoReflex\IdentityConnector\Jwt\VerifiedToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Accès, pour la requête en cours, à ce que le connecteur a établi : façade `Identity`.
 */
class IdentityManager
{
    public function __construct(private readonly Request $request, private readonly IdentityClient $client) {}

    /**
     * Le token vérifié de la requête en cours (après `identity.auth`).
     */
    public function token(): ?VerifiedToken
    {
        $token = $this->request->attributes->get(AuthenticateIdentity::ATTRIBUTE);

        return $token instanceof VerifiedToken ? $token : null;
    }

    /**
     * Le profil local de la requête en cours (après `identity.profile`).
     */
    public function profile(): ?Authenticatable
    {
        $user = $this->request->user();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Organisations de la personne de la requête en cours, lues dans Identity avec son token.
     *
     * @return list<Organization>
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function organizations(): array
    {
        return $this->client->organizations((string) $this->request->bearerToken());
    }

    /**
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function organization(string $id): ?Organization
    {
        return $this->client->organization((string) $this->request->bearerToken(), $id);
    }

    public function client(): IdentityClient
    {
        return $this->client;
    }
}
