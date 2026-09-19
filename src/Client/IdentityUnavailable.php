<?php

namespace AutoReflex\IdentityConnector\Client;

use RuntimeException;

/**
 * Identity ne répond pas (réseau, timeout, 5xx, 429) : le produit dégrade l'affichage au lieu de
 * tomber (AR-033). Réessayer plus tard a du sens.
 */
class IdentityUnavailable extends RuntimeException {}
