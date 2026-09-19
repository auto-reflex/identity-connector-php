<?php

namespace Workbench\App\Providers;

use AutoReflex\IdentityConnector\Events\AccountDeletionCancelled;
use AutoReflex\IdentityConnector\Events\AccountDeletionDue;
use AutoReflex\IdentityConnector\Events\AccountDeletionRequested;
use AutoReflex\IdentityConnector\Events\AccountReinstated;
use AutoReflex\IdentityConnector\Events\AccountSuspended;
use AutoReflex\IdentityConnector\Profiles\ProfileStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Workbench\App\EloquentProfileStore;
use Workbench\App\Listeners\ApplyIdentityDeletion;
use Workbench\App\Listeners\ApplyIdentitySuspension;

/**
 * Application de test du connecteur : une API produit minimale, telle qu'AutoTrackly ou AutoDonuts la brancheront.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProfileStore::class, EloquentProfileStore::class);

        // Sous `testbench serve` (smoke contre un vrai Identity), la base doit survivre aux requêtes : un fichier SQLite.
        if (is_string($path = env('WORKBENCH_DB'))) {
            config(['database.connections.workbench' => ['driver' => 'sqlite', 'database' => dirname(__DIR__, 3).'/'.$path, 'foreign_key_constraints' => true], 'database.default' => 'workbench']);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        Event::listen([AccountSuspended::class, AccountReinstated::class], ApplyIdentitySuspension::class);
        Event::listen([AccountDeletionRequested::class, AccountDeletionCancelled::class, AccountDeletionDue::class], ApplyIdentityDeletion::class);
    }
}
