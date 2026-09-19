<?php

namespace AutoReflex\IdentityConnector\Facades;

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\Organization;
use AutoReflex\IdentityConnector\IdentityManager;
use AutoReflex\IdentityConnector\Jwt\VerifiedToken;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;

/**
 * @method static VerifiedToken|null token()
 * @method static Authenticatable|null profile()
 * @method static list<Organization> organizations()
 * @method static Organization|null organization(string $id)
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
