<?php

namespace Workbench\App\Listeners;

use AutoGteck\IdentityConnector\Events\VehicleDeleted;
use AutoGteck\IdentityConnector\Events\VehicleUnlinked;
use Workbench\App\Models\VehicleNote;

/**
 * Ce que fait un produit quand un véhicule disparaît ou n'est plus lié à lui : il ferme ses données locales rattachées à
 * `identity_vehicle_id` (AR-059). Idempotent.
 */
class CloseLocalVehicleData
{
    public function handle(VehicleDeleted|VehicleUnlinked $event): void
    {
        VehicleNote::query()->where('identity_vehicle_id', $event->vehicleId)->delete();
    }
}
