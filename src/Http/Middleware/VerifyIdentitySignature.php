<?php

namespace AutoReflex\IdentityConnector\Http\Middleware;

use AutoReflex\IdentityConnector\Webhooks\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse tout webhook dont la signature (sur le corps brut), l'horodatage ou la taille ne conviennent pas.
 * Aucun détail n'est donné à l'appelant.
 */
class VerifyIdentitySignature
{
    private const MAX_BODY_BYTES = 16384;

    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload_too_large'], 413);
        }

        $secrets = array_values(array_filter((array) config('identity-connector.webhooks.secrets'), 'is_string'));

        if (! WebhookSignature::verify($request->header(WebhookSignature::HEADER), $body, $secrets, (int) config('identity-connector.webhooks.tolerance'))) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        return $next($request);
    }
}
