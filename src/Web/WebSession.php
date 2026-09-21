<?php

namespace AutoGteck\IdentityConnector\Web;

use AutoGteck\IdentityConnector\Client\IdentityRejected;
use AutoGteck\IdentityConnector\Client\IdentityUnavailable;
use AutoGteck\IdentityConnector\Jwt\InvalidAccessToken;
use AutoGteck\IdentityConnector\Jwt\KeySetUnavailable;
use Carbon\Carbon;
use Closure;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;
use JsonException;
use LogicException;

/**
 * Où vit une connexion web (AR-072). La session Laravel ne porte QUE l'identifiant de la connexion ; les jetons, chiffrés, sont dans le
 * cache. Pourquoi : un refresh consomme l'ancien refresh token. Deux requêtes parallèles (une page et ses images) partiraient chacune
 * de leur copie de session, et la seconde présenterait un refresh token déjà utilisé : la personne serait déconnectée. Ici le
 * renouvellement se fait sous verrou et relit la connexion dans le verrou.
 */
final class WebSession
{
    public const SESSION_KEY = 'identity_web_connection';

    public function __construct(
        private readonly Cache $cache,
        private readonly StringEncrypter $encrypter,
        private readonly int $ttlSeconds,
        private readonly int $marginSeconds,
    ) {}

    public function current(Session $session): ?WebConnection
    {
        $id = $session->get(self::SESSION_KEY);

        return is_string($id) && $id !== '' ? $this->load($id) : null;
    }

    public function newId(): string
    {
        return Str::random(40);
    }

    /**
     * Enregistre la connexion et la rattache à la session (à appeler après `regenerate()`).
     */
    public function begin(Session $session, WebConnection $connection): void
    {
        $this->store($connection);
        $session->put(self::SESSION_KEY, $connection->id);
    }

    public function store(WebConnection $connection): void
    {
        $this->cache->put($this->key($connection->id), $this->encrypter->encryptString(json_encode($connection->toArray(), JSON_THROW_ON_ERROR)), $this->ttlSeconds);
    }

    public function forget(Session $session): void
    {
        $id = $session->pull(self::SESSION_KEY);

        if (is_string($id) && $id !== '') {
            $this->cache->forget($this->key($id));
        }
    }

    /**
     * L'access token arrive à échéance (ou l'est) : il faut le renouveler.
     */
    public function due(WebConnection $connection): bool
    {
        return $connection->token->expiresAt - $this->marginSeconds <= Carbon::now()->getTimestamp();
    }

    /**
     * Renouvelle la connexion sous verrou, une seule fois même si plusieurs requêtes arrivent ensemble : celles qui attendaient
     * trouvent la connexion déjà renouvelée et s'en servent, sans rappeler Identity.
     *
     * @param  Closure(WebConnection): WebConnection  $renew
     *
     * @throws LockTimeoutException
     * @throws IdentityRejected
     * @throws IdentityUnavailable
     * @throws InvalidAccessToken
     * @throws KeySetUnavailable
     */
    public function renew(WebConnection $connection, Closure $renew): ?WebConnection
    {
        $store = $this->cache->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException('identity-connector: the cache store of the web sessions must support locks (database, redis, file, array).');
        }

        return $store->lock('identity-web-renew:'.$connection->id, 15)->block(5, function () use ($connection, $renew): ?WebConnection {
            $latest = $this->load($connection->id);

            if ($latest === null || ! $this->due($latest)) {
                return $latest;
            }

            $renewed = $renew($latest);
            $this->store($renewed);

            return $renewed;
        });
    }

    private function load(string $id): ?WebConnection
    {
        $stored = $this->cache->get($this->key($id));

        if (! is_string($stored)) {
            return null;
        }

        try {
            $data = json_decode($this->encrypter->decryptString($stored), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($data) ? WebConnection::fromArray($data) : null;
    }

    private function key(string $id): string
    {
        return 'identity-connector:web:'.$id;
    }
}
