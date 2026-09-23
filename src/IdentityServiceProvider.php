<?php

namespace AutoGteck\IdentityConnector;

use AutoGteck\IdentityConnector\Client\IdentityClient;
use AutoGteck\IdentityConnector\Client\VehicleClient;
use AutoGteck\IdentityConnector\Http\Controllers\IdentityWebhookController;
use AutoGteck\IdentityConnector\Http\Middleware\AuthenticateIdentity;
use AutoGteck\IdentityConnector\Http\Middleware\RequireRole;
use AutoGteck\IdentityConnector\Http\Middleware\RequireWebIdentity;
use AutoGteck\IdentityConnector\Http\Middleware\ResolveProfile;
use AutoGteck\IdentityConnector\Http\Middleware\VerifyIdentitySignature;
use AutoGteck\IdentityConnector\Jwt\JwtVerifier;
use AutoGteck\IdentityConnector\Jwt\KeySetProvider;
use AutoGteck\IdentityConnector\Jwt\RemoteKeySet;
use AutoGteck\IdentityConnector\Web\WebLogin;
use AutoGteck\IdentityConnector\Web\WebLoginClient;
use AutoGteck\IdentityConnector\Web\WebRoutes;
use AutoGteck\IdentityConnector\Web\WebSession;
use AutoGteck\IdentityConnector\Webhooks\WebhookVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/identity-connector.php', 'identity-connector');

        $this->app->singleton(KeySetProvider::class, function ($app): RemoteKeySet {
            $config = $app['config'];
            $url = $this->jwksUrl();

            if (! str_starts_with($url, 'https://') && ! $app->environment(['local', 'testing'])) {
                throw new InvalidArgumentException('identity-connector: the JWKS URL must use HTTPS outside local and testing environments.');
            }

            return new RemoteKeySet(
                cache: $app['cache']->store($config->get('identity-connector.cache.store')),
                http: $app->make(Http::class),
                url: $url,
                staleSeconds: (int) $config->get('identity-connector.cache.jwks_stale_hours') * 3600,
                reloadIntervalSeconds: (int) $config->get('identity-connector.cache.jwks_reload_seconds'),
                timeoutSeconds: (int) $config->get('identity-connector.http.timeout'),
            );
        });

        $this->app->bind(WebhookVerifier::class, fn ($app) => new WebhookVerifier(
            $app->make(KeySetProvider::class),
            $this->required('issuer'),
            $this->required('audience'),
            (int) $app['config']->get('identity-connector.webhooks.tolerance'),
        ));

        $this->app->singleton(JwtVerifier::class, fn ($app) => new JwtVerifier(
            $app->make(KeySetProvider::class),
            $this->required('issuer'),
            $this->required('audience'),
            (int) $app['config']->get('identity-connector.leeway'),
        ));

        $this->app->singleton(IdentityClient::class, fn ($app) => new IdentityClient(
            $app->make(Http::class),
            $app['cache']->store($app['config']->get('identity-connector.cache.store')),
            $app->make(StringEncrypter::class),
            $this->baseUrl(),
            (int) $app['config']->get('identity-connector.http.timeout'),
            (int) $app['config']->get('identity-connector.http.connect_timeout'),
            $app['config']->get('identity-connector.service.client_id') ?: null,
            $app['config']->get('identity-connector.service.client_secret') ?: null,
            array_filter((array) $app['config']->get('identity-connector.exchange.client_secrets'), 'is_string'),
        ));

        $this->app->when(IdentityWebhookController::class)->needs(Cache::class)
            ->give(fn ($app) => $app['cache']->store($app['config']->get('identity-connector.cache.store')));

        $this->app->singleton(VehicleClient::class, fn ($app) => new VehicleClient(
            $app->make(IdentityClient::class),
            $app['cache']->store($app['config']->get('identity-connector.cache.store')),
            (int) $app['config']->get('identity-connector.vehicles.cache_seconds'),
            (int) $app['config']->get('identity-connector.vehicles.stale_seconds'),
        ));

        // Connexion d'une application web (AR-072) : rien n'est construit, donc rien n'est exigé, tant qu'une route web ne s'en sert pas.
        $this->app->singleton(WebLoginClient::class, fn ($app) => new WebLoginClient(
            $app->make(IdentityClient::class),
            $this->required('issuer'),
            $this->webRequired('client_id'),
            $this->webRequired('client_secret'),
            $this->scopes(),
        ));

        $this->app->singleton(WebSession::class, fn ($app) => new WebSession(
            $app['cache']->store($app['config']->get('identity-connector.cache.store')),
            $app->make(StringEncrypter::class),
            max(60, (int) $app['config']->get('identity-connector.web.ttl_minutes')) * 60,
            max(0, (int) $app['config']->get('identity-connector.web.refresh_margin')),
        ));

        $this->app->singleton(WebLogin::class, fn ($app) => new WebLogin(
            $app->make(WebLoginClient::class),
            $app->make(IdentityClient::class),
            $app->make(JwtVerifier::class),
            $app->make(WebSession::class),
            $this->webRequired('scope'),
        ));

        $this->app->scoped(IdentityManager::class, fn ($app) => new IdentityManager($app, $app->make(IdentityClient::class), $app->make(VehicleClient::class)));
    }

    public function boot(): void
    {
        Route::aliasMiddleware('identity.auth', AuthenticateIdentity::class);
        Route::aliasMiddleware('identity.profile', ResolveProfile::class);
        Route::aliasMiddleware('identity.role', RequireRole::class);
        Route::aliasMiddleware('identity.web', RequireWebIdentity::class);

        // `Route::identityWeb()`, à appeler dans le groupe de l'application. Une macro et non la façade : enregistrer des routes ne doit
        // pas construire les clients d'Identity (ni exiger leur configuration) au démarrage, ni sous `route:cache`.
        Route::macro('identityWeb', fn (string $path = 'auth') => WebRoutes::register($path));

        RateLimiter::for('identity-web', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        $this->registerWebhookRoute();

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/identity-connector.php' => config_path('identity-connector.php')], 'identity-connector-config');
        }
    }

    /**
     * Sans secret configuré, la route existe mais refuse tout (401) : elle échoue fermée, et l'échec reste
     * visible côté Identity plutôt que de disparaître derrière un 404.
     */
    private function registerWebhookRoute(): void
    {
        $path = config('identity-connector.webhooks.path');

        if (! is_string($path) || $path === '') {
            return;
        }

        RateLimiter::for('identity-webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        Route::post($path, IdentityWebhookController::class)
            ->middleware(['throttle:identity-webhooks', VerifyIdentitySignature::class])
            ->name('identity-connector.webhooks');
    }

    private function baseUrl(): string
    {
        $configured = config('identity-connector.url');

        return is_string($configured) && $configured !== '' ? $configured : $this->required('issuer');
    }

    private function jwksUrl(): string
    {
        $configured = config('identity-connector.jwks_url');

        return is_string($configured) && $configured !== ''
            ? $configured
            : rtrim($this->required('issuer'), '/').'/.well-known/jwks.json';
    }

    private function webRequired(string $key): string
    {
        $value = config("identity-connector.web.{$key}");

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("identity-connector: the web login needs `web.{$key}` (IDENTITY_WEB_".strtoupper($key).').');
        }

        return $value;
    }

    /**
     * Les scopes demandés à la connexion : `profile` (le nom) et le scope d'accès du produit.
     */
    private function scopes(): string
    {
        return 'profile '.$this->webRequired('scope');
    }

    private function required(string $key): string
    {
        $value = config("identity-connector.{$key}");

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("identity-connector: `{$key}` is not configured (IDENTITY_".strtoupper($key).').');
        }

        return $value;
    }
}
