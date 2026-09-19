<?php

namespace Workbench\App\Providers;

use AutoReflex\IdentityConnector\Profiles\ProfileStore;
use Illuminate\Support\ServiceProvider;
use Workbench\App\EloquentProfileStore;

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
    }
}
