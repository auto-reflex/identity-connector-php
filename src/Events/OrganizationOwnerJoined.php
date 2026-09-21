<?php

namespace AutoGteck\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le propriétaire d'une organisation que ce produit a provisionnée l'a rejointe (AR-070) : le produit relie sa donnée locale
 * (`$reference`, l'identifiant qu'il avait fourni) à `$organizationId` et à `$userId`.
 */
class OrganizationOwnerJoined
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
        public readonly string $reference,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
