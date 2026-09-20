<?php

namespace AutoGteck\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Le lien de ce produit avec un véhicule a été retiré par le propriétaire, depuis le portail par exemple (AR-059) : le produit ferme
 * ou anonymise ses données locales rattachées à `vehicle_id`. Un retrait demandé par le produit lui-même n'envoie pas d'événement.
 */
class VehicleUnlinked
{
    use Dispatchable;

    public function __construct(
        public readonly string $vehicleId,
        public readonly string $product,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
