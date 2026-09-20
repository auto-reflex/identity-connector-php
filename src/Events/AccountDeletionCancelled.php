<?php

namespace AutoGteck\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La personne s'est reconnectée : la suppression est annulée (AR-055). Le produit **déverrouille** le profil.
 */
class AccountDeletionCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
