<?php

namespace Tests;

use AutoReflex\IdentityConnector\IdentityServiceProvider;
use AutoReflex\IdentityConnector\Testing\FakesIdentity;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use FakesIdentity;

    protected function getPackageProviders($app): array
    {
        return [IdentityServiceProvider::class, WorkbenchServiceProvider::class];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
