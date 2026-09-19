<?php

namespace Workbench\App;

use AutoReflex\IdentityConnector\Profiles\IdentityUser;
use AutoReflex\IdentityConnector\Profiles\ProfileStore;
use Illuminate\Contracts\Auth\Authenticatable;
use Workbench\App\Models\Profile;

class EloquentProfileStore implements ProfileStore
{
    public function find(string $identityUserId): ?Authenticatable
    {
        return Profile::query()->where('identity_user_id', $identityUserId)->first();
    }

    public function create(IdentityUser $user): Authenticatable
    {
        // `create` (et non `firstOrCreate`) : l'unicité de la base arbitre les créations concurrentes.
        return Profile::query()->create([
            'identity_user_id' => $user->id,
            'display_name' => $user->name,
            'locale' => $user->locale,
        ]);
    }
}
