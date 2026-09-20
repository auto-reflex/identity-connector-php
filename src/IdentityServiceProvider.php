<?php

namespace AutoReflex\IdentityConnector;

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Client\VehicleClient;
use AutoReflex\IdentityConnector\Http\Controllers\IdentityWebhookController;
use AutoReflex\IdentityConnector\Http\Middleware\AuthenticateIdentity;
use AutoReflex\IdentityConnector\Http\Middleware\ResolveProfile;
use AutoReflex\IdentityConnector\Http\Middleware\VerifyIdentitySignature;
use AutoReflex\IdentityConnector\Jwt\JwtVerifier;
use AutoReflex\IdentityConnector\Jwt\KeySetProvider;
use AutoReflex\IdentityConnector\Jwt\RemoteKeySet;
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

        $this->app->scoped(IdentityManager::class, fn ($app) => new IdentityManager($app, $app->make(IdentityClient::class), $app->make(VehicleClient::class)));
    }

    public function boot(): void
    {
        Route::aliasMiddleware('identity.auth', AuthenticateIdentity::class);
        Route::aliasMiddleware('identity.profile', ResolveProfile::class);

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

    private function required(string $key): string
    {
        $value = config("identity-connector.{$key}");

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("identity-connector: `{$key}` is not configured (IDENTITY_".strtoupper($key).').');
        }

        return $value;
    }
}
