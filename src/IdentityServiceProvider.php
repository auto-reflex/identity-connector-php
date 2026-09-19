<?php

namespace AutoReflex\IdentityConnector;

use AutoReflex\IdentityConnector\Client\IdentityClient;
use AutoReflex\IdentityConnector\Http\Middleware\AuthenticateIdentity;
use AutoReflex\IdentityConnector\Http\Middleware\ResolveProfile;
use AutoReflex\IdentityConnector\Jwt\JwtVerifier;
use AutoReflex\IdentityConnector\Jwt\KeySetProvider;
use AutoReflex\IdentityConnector\Jwt\RemoteKeySet;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Request;
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

        $this->app->scoped(IdentityManager::class, fn ($app) => new IdentityManager($app->make(Request::class), $app->make(IdentityClient::class)));
    }

    public function boot(): void
    {
        Route::aliasMiddleware('identity.auth', AuthenticateIdentity::class);
        Route::aliasMiddleware('identity.profile', ResolveProfile::class);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/identity-connector.php' => config_path('identity-connector.php')], 'identity-connector-config');
        }
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
