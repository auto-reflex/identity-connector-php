<?php

namespace Workbench\App\Models;

use AutoReflex\IdentityConnector\Profiles\SuspendableProfile;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * Profil produit du workbench : ce qu'une API métier stocke, lié par `identity_user_id` (ARCHITECTURE §5).
 *
 * @property int $id
 * @property string $identity_user_id
 * @property string|null $display_name
 * @property string|null $locale
 * @property Carbon|null $product_suspended_at
 */
class Profile extends Authenticatable implements SuspendableProfile
{
    protected $table = 'profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['product_suspended_at' => 'datetime'];
    }

    public function isSuspended(): bool
    {
        return $this->product_suspended_at !== null;
    }
}
