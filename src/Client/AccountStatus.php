<?php

namespace AutoReflex\IdentityConnector\Client;

/**
 * État d'un compte Identity, pour se réconcilier après un webhook manqué (AR-053, AR-054).
 */
final readonly class AccountStatus
{
    public function __construct(public string $id, public bool $exists, public bool $suspended) {}

    /**
     * Le compte peut encore utiliser le produit : il existe et n'est pas suspendu.
     */
    public function isActive(): bool
    {
        return $this->exists && ! $this->suspended;
    }
}
