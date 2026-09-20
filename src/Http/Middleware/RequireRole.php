<?php

namespace AutoGteck\IdentityConnector\Http\Middleware;

use AutoGteck\IdentityConnector\Jwt\VerifiedToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige un rôle d'équipe dans ce produit (AR-066) : `identity.role:admin`, ou `identity.role:admin,moderator` pour l'un
 * ou l'autre. Les rôles viennent du token (claim `roles`, calculé par Identity : un super administrateur a tous les rôles) ;
 * ce que chaque rôle permet reste décidé par le produit. À placer après `identity.auth`.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $token = $request->attributes->get(AuthenticateIdentity::ATTRIBUTE);

        if (! $token instanceof VerifiedToken) {
            return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        foreach ($roles as $role) {
            if ($token->hasRole($role)) {
                return $next($request);
            }
        }

        return response()->json(['error' => 'insufficient_role'], 403);
    }
}
