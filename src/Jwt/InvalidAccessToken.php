<?php

namespace AutoGteck\IdentityConnector\Jwt;

use RuntimeException;

/**
 * Le token n'est pas un access token Identity valide pour cette API : la requête est refusée (401).
 */
class InvalidAccessToken extends RuntimeException {}
