<?php

namespace AutoReflex\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Une organisation Identity a disparu avec son unique membre (AR-055) : le produit ferme l'extension locale
 * qui la référençait par `identity_organization_id`.
 */
class OrganizationDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $organizationId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
