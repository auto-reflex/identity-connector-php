<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Identity refuse le SIRET d'une organisation (AR-075) : une **erreur de formulaire**, à montrer à la personne, pas une panne.
 * `$error` : `siret_invalid` (format ou clé de Luhn), `siret_not_found` (inconnu de Sirene, ou non diffusible), `siret_inactive`
 * (établissement fermé) ou `siret_taken` (déjà porté par une organisation : Identity ne dit pas laquelle).
 */
class LegalIdentityRejected extends IdentityRejected
{
    public const CODES = ['siret_invalid', 'siret_not_found', 'siret_inactive', 'siret_taken'];
}
