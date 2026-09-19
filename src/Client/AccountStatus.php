<?php

namespace AutoReflex\IdentityConnector\Client;

use Carbon\CarbonImmutable;

/**
 * État d'un compte Identity, pour se réconcilier après un webhook manqué (AR-053, AR-054).
 */
final readonly class AccountStatus
{
    /**
     * @param  string|null  $deletion  `null`, `pending` (suppression demandée : profil verrouillé) ou `processing`
     *                                 (effacement demandé : effacer puis accuser)
     */
    public function __construct(
        public string $id,
        public bool $exists,
        public bool $suspended,
        public ?string $deletion = null,
        public ?CarbonImmutable $deletionScheduledFor = null,
    ) {}

    /**
     * Le compte peut utiliser le produit : il existe, n'est pas suspendu et n'est pas en cours de suppression.
     */
    public function isActive(): bool
    {
        return $this->exists && ! $this->suspended && $this->deletion === null;
    }
}
