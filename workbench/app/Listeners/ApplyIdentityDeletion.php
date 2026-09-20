<?php

namespace Workbench\App\Listeners;

use AutoGteck\IdentityConnector\Events\AccountDeletionCancelled;
use AutoGteck\IdentityConnector\Events\AccountDeletionDue;
use AutoGteck\IdentityConnector\Events\AccountDeletionRequested;
use AutoGteck\IdentityConnector\Facades\Identity;
use Workbench\App\Models\Profile;

/**
 * Ce que fait un produit de la suppression d'un compte AutoGteck (AR-055, AR-056) :
 * demandée → il verrouille le profil ; annulée → il le déverrouille ; échue → il **efface**, puis **accuse**.
 * Si l'accusé échoue, l'exception remonte : Identity renverra l'événement, et l'effacement se rejoue sans effet.
 */
class ApplyIdentityDeletion
{
    public function handle(AccountDeletionRequested|AccountDeletionCancelled|AccountDeletionDue $event): void
    {
        $profiles = Profile::query()->where('identity_user_id', $event->userId);

        match (true) {
            $event instanceof AccountDeletionRequested => $profiles->update(['identity_deletion_at' => $event->scheduledFor]),
            $event instanceof AccountDeletionCancelled => $profiles->update(['identity_deletion_at' => null]),
            $event instanceof AccountDeletionDue => $this->erase($event->userId),
        };
    }

    private function erase(string $userId): void
    {
        Profile::query()->where('identity_user_id', $userId)->delete();

        Identity::client()->acknowledgeDeletion($userId);
    }
}
