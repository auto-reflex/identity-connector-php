<?php

namespace AutoGteck\IdentityConnector\Http\Middleware;

use AutoGteck\IdentityConnector\Jwt\KeySetUnavailable;
use AutoGteck\IdentityConnector\Webhooks\WebhookSignature;
use AutoGteck\IdentityConnector\Webhooks\WebhookVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse tout webhook dont la signature (JWT de la clé d'Identity, lié au corps brut, AR-087) ou la taille ne conviennent pas.
 * Aucun détail n'est donné à l'appelant. Si les clés d'Identity sont introuvables, 503 : Identity réessaiera.
 */
class VerifyIdentitySignature
{
    private const MAX_BODY_BYTES = 16384;

    public function __construct(private readonly WebhookVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        try {
            $valid = $this->verifier->verify($request->header(WebhookSignature::HEADER), $body);
        } catch (KeySetUnavailable) {
            return response()->json(['error' => 'identity_unavailable'], 503);
        }

        if (! $valid) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
