<?php

namespace AutoGteck\IdentityConnector\Facades;

use AutoGteck\IdentityConnector\Client\IdentityClient;
use AutoGteck\IdentityConnector\Client\Organization;
use AutoGteck\IdentityConnector\Client\PersonVehicles;
use AutoGteck\IdentityConnector\Client\VehicleClient;
use AutoGteck\IdentityConnector\IdentityManager;
use AutoGteck\IdentityConnector\Jwt\VerifiedToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static VerifiedToken|null token()
 * @method static Authenticatable|null profile()
 * @method static list<Organization> organizations()
 * @method static Organization|null organization(string $id)
 * @method static PersonVehicles vehicles()
 * @method static VehicleClient vehicleClient()
 * @method static IdentityClient client()
 *
 * @see IdentityManager
 */
class Identity extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IdentityManager::class;
    }
}
