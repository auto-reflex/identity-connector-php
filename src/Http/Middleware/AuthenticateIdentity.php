<?php

namespace AutoReflex\IdentityConnector\Http\Middleware;

use AutoReflex\IdentityConnector\Jwt\InvalidAccessToken;
use AutoReflex\IdentityConnector\Jwt\JwtVerifier;
use AutoReflex\IdentityConnector\Jwt\KeySetUnavailable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

/**
 * Authentifie une requête par access token Identity (AR-052) : signature, émetteur, audience de cette API,
 * expiration, puis scopes. Usage : `identity.auth` ou `identity.auth:{scope},{scope}`.
 *
 * Le token vérifié est disponible par `Identity::token()`. Aucun appel à Identity : la durée de vie
 * courte des tokens fait foi (AR-033).
 */
class AuthenticateIdentity
{
    public const ATTRIBUTE = 'identity.token';

    public function __construct(private readonly JwtVerifier $verifier) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        try {
            $token = $this->verifier->verify(JwtVerifier::extractBearer($request->header('Authorization')));
        } catch (InvalidAccessToken|UnexpectedValueException) {
            return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        } catch (KeySetUnavailable) {
            return response()->json(['error' => 'identity_unavailable'], 503)->header('Retry-After', '5');
        }

        foreach ($scopes as $scope) {
            if (! $token->hasScope($scope)) {
                return response()->json(['error' => 'insufficient_scope'], 403)
                    ->header('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="'.$scope.'"');
            }
        }

        $request->attributes->set(self::ATTRIBUTE, $token);

        return $next($request);
    }
}
