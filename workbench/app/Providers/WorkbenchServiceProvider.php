<?php

namespace Workbench\App\Providers;

use AutoReflex\IdentityConnector\Events\AccountReinstated;
use AutoReflex\IdentityConnector\Events\AccountSuspended;
use AutoReflex\IdentityConnector\Profiles\ProfileStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\EloquentProfileStore;
use Workbench\App\Listeners\ApplyIdentitySuspension;

/**
 * Application de test du connecteur : une API produit minimale, telle qu'AutoTrackly ou AutoDonuts la brancheront.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProfileStore::class, EloquentProfileStore::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen([AccountSuspended::class, AccountReinstated::class], ApplyIdentitySuspension::class);
    }
}
