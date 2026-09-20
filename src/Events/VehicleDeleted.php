<?php

namespace AutoReflex\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un véhicule auquel ce produit était lié a été supprimé, par son propriétaire ou avec son compte (AR-059) : le produit ferme ou
 * anonymise ses données locales rattachées à `vehicle_id`. Traitement idempotent.
 */
class VehicleDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $vehicleId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
