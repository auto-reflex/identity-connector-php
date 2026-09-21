<?php

namespace AutoGteck\IdentityConnector\Web;

use AutoGteck\IdentityConnector\Http\Controllers\WebCallbackController;
use AutoGteck\IdentityConnector\Http\Controllers\WebLoginController;
use AutoGteck\IdentityConnector\Http\Controllers\WebLogoutController;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * Les trois routes de connexion web (AR-072). L'application les enregistre elle-même DANS son groupe (préfixe, domaine, middleware,
 * préfixe de noms) : le paquet n'a rien à deviner. Les noms portent donc le préfixe du groupe ; `name()` retrouve le vrai nom.
 */
final class WebRoutes
{
    public const LOGIN = 'identity.web.login';

    public const CALLBACK = 'identity.web.callback';

    public const LOGOUT = 'identity.web.logout';

    /**
     * À appeler dans un groupe qui porte les middlewares `web` (session, CSRF).
     */
    public static function register(string $path = 'auth'): void
    {
        $path = trim($path, '/');

        Route::get($path.'/redirect', WebLoginController::class)->middleware('throttle:identity-web')->name(self::LOGIN);
        Route::get($path.'/callback', WebCallbackController::class)->middleware('throttle:identity-web')->name(self::CALLBACK);
        Route::post($path.'/logout', WebLogoutController::class)->name(self::LOGOUT);
    }

    /**
     * Le nom complet d'une de ces routes, préfixe du groupe compris (route mise en cache incluse).
     */
    public static function name(string $suffix): string
    {
        foreach (array_keys(Route::getRoutes()->getRoutesByName()) as $name) {
            if ($name === $suffix || str_ends_with($name, '.'.$suffix)) {
                return $name;
            }
        }

        throw new LogicException('identity-connector: the web login routes are not registered. Call Route::identityWeb() inside the route group of the application.');
    }
}
