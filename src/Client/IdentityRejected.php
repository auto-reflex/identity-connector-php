<?php

namespace AutoReflex\IdentityConnector\Client;

use RuntimeException;

/**
 * Identity a répondu par un refus (4xx) : token révoqué, scope manquant, ressource introuvable.
 * Réessayer à l'identique ne servirait à rien.
 */
class IdentityRejected extends RuntimeException
{
    public function __construct(public readonly int $status, string $message = '', public readonly ?string $error = null)
    {
        parent::__construct($message !== '' ? $message : "Identity refused the request (HTTP {$status}).");
    }
}
