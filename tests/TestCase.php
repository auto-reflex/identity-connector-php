<?php

namespace Tests;

use AutoGteck\IdentityConnector\IdentityServiceProvider;
use AutoGteck\IdentityConnector\Testing\FakesIdentity;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use FakesIdentity;

    protected function getPackageProviders($app): array
    {
        return [IdentityServiceProvider::class, WorkbenchServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        // Indépendant de testbench.yaml (qui sert au smoke) : cache en mémoire, base SQLite en mémoire.
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
