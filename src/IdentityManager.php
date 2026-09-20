<?php

namespace AutoReflex\IdentityConnector;

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\IdentityUnavailable;
use AutoReflex\IdentityConnector\Client\Organization;
use AutoReflex\IdentityConnector\Client\PersonVehicles;
use AutoReflex\IdentityConnector\Client\VehicleClient;
use AutoReflex\IdentityConnector\Http\Middleware\AuthenticateIdentity;
use AutoReflex\IdentityConnector\Jwt\VerifiedToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Accès, pour la requête en cours, à ce que le connecteur a établi : façade `Identity`.
 */
class IdentityManager
{
    public function __construct(private readonly Container $app, private readonly IdentityClient $client, private readonly VehicleClient $vehicleClient) {}

    /**
     * La requête EN COURS, lue à chaque appel : le gestionnaire peut survivre à une requête (Octane, tests).
     */
    private function request(): Request
    {
        return $this->app->make('request');
    }

    /**
     * Le token vérifié de la requête en cours (après `identity.auth`).
     */
    public function token(): ?VerifiedToken
    {
        $token = $this->request()->attributes->get(AuthenticateIdentity::ATTRIBUTE);

        return $token instanceof VerifiedToken ? $token : null;
    }

    /**
     * La personne de la requête en cours a-t-elle ce rôle d'équipe dans ce produit (AR-066) ?
     */
    public function hasRole(string $role): bool
    {
        return $this->token()?->hasRole($role) ?? false;
    }

    /**
     * Le profil local de la requête en cours (après `identity.profile`).
     */
    public function profile(): ?Authenticatable
    {
        $user = $this->request()->user();

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
        return $this->client->organizations((string) $this->request()->bearerToken());
    }

    /**
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function organization(string $id): ?Organization
    {
        return $this->client->organization((string) $this->request()->bearerToken(), $id);
    }

    /**
     * Les véhicules de la personne de la requête en cours (garage, lecture, création, liens…), avec son token.
     */
    public function vehicles(): PersonVehicles
    {
        return new PersonVehicles($this->vehicleClient, (string) $this->request()->bearerToken());
    }

    /**
     * Les véhicules à l'échelle du produit : lectures de tiers en service à service (`publicGet`, `publicMany`).
     */
    public function vehicleClient(): VehicleClient
    {
        return $this->vehicleClient;
    }

    public function client(): IdentityClient
    {
        return $this->client;
    }
}
