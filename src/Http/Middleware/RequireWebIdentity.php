<?php

namespace AutoGteck\IdentityConnector\Http\Middleware;

use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\IdentityUnavailable;
use AutoGteck\IdentityConnector\Jwt\InvalidAccessToken;
use AutoGteck\IdentityConnector\Jwt\KeySetUnavailable;
use AutoGteck\IdentityConnector\Web\WebConnection;
use AutoGteck\IdentityConnector\Web\WebLogin;
use AutoGteck\IdentityConnector\Web\WebSession;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protège les pages d'une application web par la connexion Identity (AR-072) : `identity.web` ou `identity.web:admin` (un des rôles
 * listés, sinon 403 : page HTML de l'application).
 *
 * La connexion est renouvelée à l'approche de l'échéance du token d'accès (15 minutes) : un rôle retiré ou un compte suspendu
 * prend effet à ce moment-là. Identity injoignable : la connexion vit jusqu'à l'échéance du token puis se ferme, jamais plus.
 * Le token vérifié est lu par `Identity::token()` / `Identity::hasRole()`, la personne par `Identity::web()`.
 */
class RequireWebIdentity implements AuthenticatesRequests
{
    public const ATTRIBUTE = 'identity.web';

    public function __construct(private readonly WebSession $sessions, private readonly WebLogin $login) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $session = $request->session();
        $connection = $this->sessions->current($session);

        if ($connection === null) {
            return $this->login->toLogin($request);
        }

        if ($this->sessions->due($connection)) {
            try {
                $connection = $this->sessions->renew($connection, $this->login->renew(...));
            } catch (IdentityRejected|InvalidAccessToken) {
                $this->sessions->forget($session);

                return $this->login->toLogin($request, 'session_expired');
            } catch (IdentityUnavailable|KeySetUnavailable|LockTimeoutException) {
                // Le token en main reste bon jusqu'à son échéance.
                if ($connection->token->expiresAt <= Carbon::now()->getTimestamp()) {
                    $this->sessions->forget($session);

                    return $this->login->toLogin($request, 'unavailable');
                }
            }

            if ($connection === null) {
                $this->sessions->forget($session);

                return $this->login->toLogin($request, 'session_expired');
            }
        }

        $request->attributes->set(AuthenticateIdentity::ATTRIBUTE, $connection->token);
        $request->attributes->set(self::ATTRIBUTE, $connection->identity());

        if ($roles !== [] && ! $this->hasAnyRole($connection, $roles)) {
            abort(403);
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $roles
     */
    private function hasAnyRole(WebConnection $connection, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($connection->token->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
