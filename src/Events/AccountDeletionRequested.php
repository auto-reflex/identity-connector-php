<?php

namespace AutoReflex\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La personne a demandé la suppression de son compte AutoReflex (AR-055) : le produit **verrouille** son profil,
 * sans l'effacer. L'effacement n'a lieu qu'à `scheduledFor`, sur `AccountDeletionDue`, sauf annulation.
 */
class AccountDeletionRequested
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
        public readonly CarbonImmutable $scheduledFor,
    ) {}
}
