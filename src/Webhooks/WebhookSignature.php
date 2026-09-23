<?php

namespace AutoGteck\IdentityConnector\Webhooks;

/**
 * Forme de la signature des webhooks d'Identity (AR-087) : l'en-tête `Identity-Signature` porte un JWT RS256 signé par la clé
 * d'Identity (celle du JWKS), de type `identity-webhook+jwt`, dont le claim `body` est l'empreinte SHA-256 (base64url, sans
 * remplissage) du corps brut. Aucun secret partagé.
 */
final class WebhookSignature
{
    public const HEADER = 'Identity-Signature';

    public const TYPE = 'identity-webhook+jwt';

    public static function digest(string $body): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $body, true)), '+/', '-_'), '=');
    }
}
