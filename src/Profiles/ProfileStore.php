<?php

namespace AutoReflex\IdentityConnector\Profiles;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Point d'accès du produit à ses profils locaux (AR-052). Le package ne possède aucune table : le produit
 * garde son modèle, avec un `identity_user_id` unique.
 */
interface ProfileStore
{
    public function find(string $identityUserId): ?Authenticatable;

    /**
     * Crée le profil à la première connexion. Doit échouer par une violation d'unicité (et non créer un
     * doublon) si un autre processus l'a créé entre-temps : le connecteur relit alors le profil existant.
     */
    public function create(IdentityUser $user): Authenticatable;
}
