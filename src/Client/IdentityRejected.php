<?php

namespace AutoReflex\IdentityConnector\Client;

use RuntimeException;

/**
 * Identity a répondu par un refus (4xx) : token révoqué, scope manquant, ressource introuvable.
 * Réessayer à l'identique ne servirait à rien.
 */
class IdentityRejected extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body  le corps JSON du refus : `current_version` (412), `existing_vehicle_id` (409), `errors` (422)…
     */
    public function __construct(public readonly int $status, string $message = '', public readonly ?string $error = null, public readonly array $body = [])
    {
        parent::__construct($message !== '' ? $message : "Identity refused the request (HTTP {$status}).");
    }
}
