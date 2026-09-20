<?php

namespace AutoReflex\IdentityConnector\Http\Middleware;

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\IdentityRejected;
use AutoReflex\IdentityConnector\Client\IdentityUnavailable;
use AutoReflex\IdentityConnector\Jwt\VerifiedToken;
use AutoReflex\IdentityConnector\Profiles\ProfileStore;
use AutoReflex\IdentityConnector\Profiles\SuspendableProfile;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charge le profil local de la personne du token, et le crée à la première connexion en lisant `/userinfo`
 * avec le token reçu (AR-048, AR-052). À placer après `identity.auth`. Le profil devient l'utilisateur de la requête et du garde par défaut.
 */
class ResolveProfile
{
    public function __construct(
        private readonly ProfileStore $profiles,
        private readonly IdentityClient $identity,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->attributes->get(AuthenticateIdentity::ATTRIBUTE);

        if (! $token instanceof VerifiedToken) {
            throw new LogicException('identity.profile must run after identity.auth.');
        }

        if ($token->isClientToken()) {
            return response()->json(['error' => 'user_token_required'], 403);
        }

        $profile = $this->profiles->find($token->subject);

        if ($profile === null) {
            try {
                $profile = $this->provision($token, (string) $request->bearerToken());
            } catch (IdentityUnavailable) {
                return response()->json(['error' => 'identity_unavailable'], 503)->header('Retry-After', '5');
            } catch (IdentityRejected) {
                return response()->json(['error' => 'invalid_token'], 401)->header('WWW-Authenticate', 'Bearer error="invalid_token"');
            }

            if ($profile === null) {
                return response()->json(['error' => 'email_unverified'], 403);
            }
        }

        if ($profile instanceof SuspendableProfile && $profile->isSuspended()) {
            return response()->json(['error' => 'profile_suspended'], 403);
        }

        // Le profil est l'utilisateur de la requête *et* du garde par défaut : `auth()->user()`, `Gate` et les policies
        // (qui lisent le garde, pas la requête) voient la même personne que `$request->user()`.
        $request->setUserResolver(fn () => $profile);
        $this->auth->guard()->setUser($profile);

        return $next($request);
    }

    /**
     * @return Authenticatable|null `null` si Identity ne garantit pas l'email de la personne
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    private function provision(VerifiedToken $token, string $accessToken): ?Authenticatable
    {
        $user = $this->identity->userInfo($accessToken);

        // Le `/userinfo` doit parler de la personne du token : sinon la réponse n'est pas fiable.
        if ($user->id !== $token->subject) {
            throw new IdentityRejected(502, 'The userinfo subject does not match the token.');
        }

        if ($user->emailVerified === false) {
            return null;
        }

        try {
            return $this->profiles->create($user);
        } catch (UniqueConstraintViolationException $exception) {
            // Une requête concurrente a créé le profil entre-temps : on relit, on ne duplique pas.
            return $this->profiles->find($token->subject) ?? throw $exception;
        }
    }
}
