<?php

namespace AutoGteck\IdentityConnector\Profiles;

/**
 * Un profil produit peut être suspendu localement sans que le compte Identity le soit
 * (ARCHITECTURE §10) : le connecteur refuse alors ses requêtes (403).
 */
interface SuspendableProfile
{
    public function isSuspended(): bool;
}
