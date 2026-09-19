<?php

namespace Workbench\App\Listeners;

use AutoReflex\IdentityConnector\Events\AccountReinstated;
use AutoReflex\IdentityConnector\Events\AccountSuspended;
use Workbench\App\Models\Profile;

/**
 * Ce que fait un produit d'un webhook d'Identity : il répercute la suspension globale sur son profil, sans
 * toucher à une éventuelle suspension locale. Idempotent : rejouer l'événement ne change rien.
 */
class ApplyIdentitySuspension
{
    public function handle(AccountSuspended|AccountReinstated $event): void
    {
        Profile::query()
            ->where('identity_user_id', $event->userId)
            ->update(['identity_suspended_at' => $event instanceof AccountSuspended ? $event->occurredAt : null]);
    }
}
