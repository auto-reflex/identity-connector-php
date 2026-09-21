<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Identity refuse un provisionnement pour une raison qui n'est pas un champ invalide (AR-076). `$error` : `owner_unknown` (le
 * compte du propriétaire immédiat n'existe pas ou son email n'est pas vérifié : le faire vérifier son adresse) ou `reference_conflict`
 * (la référence désigne déjà une organisation dont le propriétaire est une autre personne).
 */
class ProvisioningRejected extends IdentityRejected
{
    public const CODES = ['owner_unknown', 'reference_conflict'];
}
