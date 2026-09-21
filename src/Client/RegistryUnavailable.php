<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Le registre des entreprises (Sirene) ne répond pas : Identity refuse de créer l'organisation plutôt que d'enregistrer un SIRET
 * non vérifié (AR-075). Une **indisponibilité**, pas une erreur de formulaire : dire à la personne de réessayer dans un instant.
 */
class RegistryUnavailable extends IdentityUnavailable {}
