<?php

namespace AutoGteck\IdentityConnector\Jwt;

use RuntimeException;

/**
 * Aucune clé de vérification n'est disponible (Identity injoignable et cache périmé ou vide) : le
 * token ne peut être ni accepté ni jugé faux, la requête reçoit 503 plutôt que 401.
 */
class KeySetUnavailable extends RuntimeException {}
