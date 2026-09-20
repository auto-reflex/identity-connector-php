<?php

namespace AutoGteck\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Identity a suspendu ce compte (AR-053) : le produit coupe ses accès sans attendre l'expiration des tokens.
 * L'événement peut arriver plusieurs fois pour un même compte ; le traitement doit être idempotent.
 */
class AccountSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
