<?php

namespace AutoReflex\IdentityConnector;

use AutoReflex\IdentityConnector\Client\IdentityClient;
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

    public function client(): IdentityClient
    {
        return $this->client;
    }
}
