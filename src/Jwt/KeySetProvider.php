<?php

namespace AutoGteck\IdentityConnector\Jwt;

use Firebase\JWT\Key;

/**
 * Source des clés publiques de vérification, indexées par `kid`.
 */
interface KeySetProvider
{
    /**
     * @return Key|null la clé du `kid`, ou `null` si Identity n'en publie aucune sous ce nom
     *
     * @throws KeySetUnavailable
     */
    public function key(string $kid): ?Key;
}
