<?php

namespace Workbench\App\Providers;

use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\IdentityUnavailable;
use AutoReflex\IdentityConnector\Events\AccountDeletionCancelled;
use AutoReflex\IdentityConnector\Events\AccountDeletionDue;
use AutoReflex\IdentityConnector\Events\AccountDeletionRequested;
use AutoReflex\IdentityConnector\Events\AccountReinstated;
use AutoReflex\IdentityConnector\Events\AccountSuspended;
use AutoReflex\IdentityConnector\Events\VehicleDeleted;
use AutoReflex\IdentityConnector\Events\VehicleUnlinked;
use AutoReflex\IdentityConnector\Profiles\ProfileStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Workbench\App\EloquentProfileStore;
use Workbench\App\Listeners\ApplyIdentityDeletion;
use Workbench\App\Listeners\ApplyIdentitySuspension;
use Workbench\App\Listeners\CloseLocalVehicleData;
use Workbench\App\Models\Profile;

/**
 * Application de test du connecteur : une API produit minimale, telle qu'AutoTrackly ou AutoDonuts la brancheront.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ProfileStore::class, EloquentProfileStore::class);

        // Un produit décide comment rendre les refus d'Identity : ici, tels quels (corps compris), et 503 si Identity est en panne.
        $this->app->afterResolving(ExceptionHandler::class, function ($handler): void {
            $handler->renderable(fn (IdentityRejected $rejected) => response()->json($rejected->body + ['error' => $rejected->error], $rejected->status));
            $handler->renderable(fn (IdentityUnavailable $unavailable) => response()->json(['error' => 'identity_unavailable'], 503));
        });

        // Sous `testbench serve` (smoke contre un vrai Identity), la base doit survivre aux requêtes : un fichier SQLite.
        if (is_string($path = env('WORKBENCH_DB'))) {
            config(['database.connections.workbench' => ['driver' => 'sqlite', 'database' => dirname(__DIR__, 3).'/'.$path, 'foreign_key_constraints' => true], 'database.default' => 'workbench']);
        }
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');

        RateLimiter::for('by-person', function (Request $request) {
            $user = $request->user();
            config(['by-person.key' => $key = $user instanceof Profile ? $user->identity_user_id : 'anonymous']);

            return Limit::perMinute(60)->by($key);
        });

        Gate::define('own-profile', fn (Profile $profile, string $identityUserId) => $profile->identity_user_id === $identityUserId);

        Event::listen([AccountSuspended::class, AccountReinstated::class], ApplyIdentitySuspension::class);
        Event::listen([VehicleDeleted::class, VehicleUnlinked::class], CloseLocalVehicleData::class);
        Event::listen([AccountDeletionRequested::class, AccountDeletionCancelled::class, AccountDeletionDue::class], ApplyIdentityDeletion::class);
    }
}
