<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Identity refuse un provisionnement pour une raison qui n'est pas un champ invalide (AR-076, AR-079). `$error` :
 * `owner_unknown` (le compte du propriétaire immédiat n'existe pas ou son email n'est pas vérifié : le faire vérifier son
 * adresse), `reference_conflict` (la référence désigne déjà une organisation dont le propriétaire est une autre personne),
 * `not_a_member` (rattachement d'une organisation dont la personne n'est ni owner ni admin) ou `already_provisioned`
 * (l'organisation à rattacher a déjà une référence, pour un autre produit ou une autre référence).
 */
class ProvisioningRejected extends IdentityRejected
{
    public const CODES = ['owner_unknown', 'reference_conflict', 'not_a_member', 'already_provisioned'];
}
