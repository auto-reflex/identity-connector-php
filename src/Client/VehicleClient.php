<?php

namespace AutoGteck\IdentityConnector\Client;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Response;

/**
 * Véhicules d'Identity (AR-059) pour un produit. Les lectures de **champs non sensibles** sont gardées en cache court, et servies
 * périmées (`Vehicle::$stale`) si Identity ne répond plus : l'affichage se dégrade au lieu de tomber (AR-033). Le groupe `sensitive`
 * n'est jamais mis en cache, ni persistant ni court. Toute écriture invalide le cache de la personne.
 */
class VehicleClient
{
    private const READER_KINDS = ['member', 'anonymous'];

    public function __construct(
        private readonly IdentityClient $client,
        private readonly Cache $cache,
        private readonly int $ttlSeconds = 60,
        private readonly int $staleSeconds = 3600,
    ) {}

    /**
     * Un véhicule de la personne (ou visible d'elle) ; `null` s'il n'existe pas ou ne lui est pas lisible.
     *
     * @param  list<string>|null  $fields  groupes demandés ; `sensitive` doit l'être explicitement
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function get(string $productToken, string $id, ?array $fields = null): ?Vehicle
    {
        $data = $this->cached($this->personKey($productToken, 'one:'.$id, $fields), $fields, function () use ($productToken, $id, $fields): ?Response {
            try {
                return $this->client->personRequest('GET', $productToken, '/api/v1/vehicles/'.rawurlencode($id), $this->query($fields));
            } catch (IdentityRejected $rejected) {
                return $rejected->status === 404 ? null : throw $rejected;
            }
        });

        return $data === null ? null : Vehicle::fromArray($data['data'], $data['stale']);
    }

    /**
     * Des véhicules par lot (50 au plus) : seuls les lisibles sont renvoyés, dans l'ordre demandé.
     *
     * @param  list<string>  $ids
     * @param  list<string>|null  $fields
     * @return list<Vehicle>
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function many(string $productToken, array $ids, ?array $fields = null): array
    {
        $data = $this->cached($this->personKey($productToken, 'many:'.implode(',', $ids), $fields), $fields, fn () => $this->client->personRequest(
            'GET', $productToken, '/api/v1/vehicles', ['query' => ['ids' => implode(',', $ids)] + $this->query($fields)['query']],
        ));

        return $this->vehicles($data);
    }

    /**
     * Le garage de la personne et les flottes de ses organisations.
     *
     * @param  list<string>|null  $fields
     * @param  string|null  $owner  `user` ou `organization:{id}`
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function garage(string $productToken, ?array $fields = null, ?string $owner = null, int $limit = 50, ?string $after = null): VehiclePage
    {
        $query = array_filter(['owner' => $owner, 'limit' => $limit, 'after' => $after], fn ($value) => $value !== null) + $this->query($fields)['query'];
        $data = $this->cached($this->personKey($productToken, 'garage:'.json_encode([$owner, $limit, $after]), $fields), $fields, fn () => $this->client->personRequest('GET', $productToken, '/api/v1/vehicles', ['query' => $query]));

        return new VehiclePage($this->vehicles($data), $data['next_cursor'] ?? null);
    }

    /**
     * Un véhicule d'un tiers, en service à service : le produit déclare son lecteur (`anonymous` ou `member`, AR-039).
     *
     * @param  list<string>|null  $fields
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function publicGet(string $id, string $reader = 'anonymous', ?string $readerRef = null, ?array $fields = null): ?Vehicle
    {
        $data = $this->cached($this->serviceKey('one:'.$id, $reader, $fields), $fields, function () use ($id, $reader, $readerRef, $fields): ?Response {
            try {
                return $this->client->serviceRequest('GET', 'vehicles:read', '/api/v1/vehicles/'.rawurlencode($id), $this->query($fields), $this->readerHeaders($reader, $readerRef));
            } catch (IdentityRejected $rejected) {
                return $rejected->status === 404 ? null : throw $rejected;
            }
        });

        return $data === null ? null : Vehicle::fromArray($data['data'], $data['stale']);
    }

    /**
     * Des véhicules de tiers par lot, en service à service.
     *
     * @param  list<string>  $ids
     * @param  list<string>|null  $fields
     * @return list<Vehicle>
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function publicMany(array $ids, string $reader = 'anonymous', ?string $readerRef = null, ?array $fields = null): array
    {
        $data = $this->cached($this->serviceKey('many:'.implode(',', $ids), $reader, $fields), $fields, fn () => $this->client->serviceRequest(
            'GET', 'vehicles:read', '/api/v1/vehicles', ['query' => ['ids' => implode(',', $ids)] + $this->query($fields)['query']], $this->readerHeaders($reader, $readerRef),
        ));

        return $this->vehicles($data);
    }

    /**
     * Crée un véhicule (garage, ou organisation avec `owner`) et, avec `link`, le lie à ce produit.
     *
     * @param  array<string, mixed>  $data  champs groupés : `identity`, `specs`, `usage`, `sensitive`, `owner`, `link`
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected {@see IdentityRejected::$body} porte `errors` (422) ou `existing_vehicle_id` (409)
     */
    public function create(string $productToken, array $data): Vehicle
    {
        $response = $this->client->personRequest('POST', $productToken, '/api/v1/vehicles', ['json' => $data]);
        $this->forget($productToken);

        return Vehicle::fromArray((array) $response->json('data'));
    }

    /**
     * Modifie un véhicule. `$version` est celle lue : Identity refuse (412, `body['current_version']`) si la fiche a changé depuis.
     * Un kilométrage inférieur exige `$confirmDecrease` (422 `mileage_decrease` sinon).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function update(string $productToken, string $id, array $data, int $version, bool $confirmDecrease = false): Vehicle
    {
        $response = $this->client->personRequest(
            'PATCH', $productToken, '/api/v1/vehicles/'.rawurlencode($id),
            ['json' => $data + ($confirmDecrease ? ['confirm_decrease' => true] : [])],
            ['If-Match' => '"'.$version.'"'],
        );
        $this->forget($productToken);

        return Vehicle::fromArray((array) $response->json('data'));
    }

    /**
     * Supprime le véhicule pour tous les produits (réservé au propriétaire).
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function delete(string $productToken, string $id): void
    {
        $this->client->personRequest('DELETE', $productToken, '/api/v1/vehicles/'.rawurlencode($id));
        $this->forget($productToken);
    }

    /**
     * Crée ou met à jour le lien de ce produit avec le véhicule : c'est ici que la personne consent aux groupes lus.
     *
     * @param  list<string>  $groups
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function link(string $productToken, string $id, array $groups, string $visibility = 'private', bool $shareUsage = false): VehicleLink
    {
        $response = $this->client->personRequest('PUT', $productToken, '/api/v1/vehicles/'.rawurlencode($id).'/link', ['json' => ['groups' => $groups, 'visibility' => $visibility, 'share_usage' => $shareUsage]]);
        $this->forget($productToken);

        return VehicleLink::fromArray((array) $response->json('data'));
    }

    /**
     * Retire le lien de ce produit : le véhicule reste dans le garage et dans les autres produits.
     *
     * @throws IdentityUnavailable
     * @throws IdentityRejected
     */
    public function unlink(string $productToken, string $id): void
    {
        $this->client->personRequest('DELETE', $productToken, '/api/v1/vehicles/'.rawurlencode($id).'/link');
        $this->forget($productToken);
    }

    /**
     * @param  callable(): (Response|null)  $fetch
     * @param  list<string>|null  $fields
     * @return array<string, mixed>|null le corps décodé, avec `stale` ; `null` si le véhicule n'existe pas
     */
    private function cached(string $key, ?array $fields, callable $fetch): ?array
    {
        $hit = $this->cache->get($key);

        if (is_array($hit)) {
            return $hit + ['stale' => false];
        }

        try {
            $response = $fetch();
        } catch (IdentityUnavailable $unavailable) {
            $stale = $this->cache->get('stale:'.$key);

            if (is_array($stale)) {
                return $stale + ['stale' => true];
            }

            throw $unavailable;
        }

        if ($response === null) {
            return null;
        }

        $body = (array) $response->json();

        // Jamais de `sensitive` en cache : ni demandé, ni renvoyé, ni annoncé `no-store` par Identity.
        $cacheable = ! in_array('sensitive', $fields ?? [], true)
            && ! str_contains((string) $response->header('Cache-Control'), 'no-store')
            && ! $this->carriesSensitiveGroup($body);

        if ($cacheable && $this->ttlSeconds > 0) {
            $this->cache->put($key, $body, $this->ttlSeconds);
            $this->cache->put('stale:'.$key, $body, $this->staleSeconds);
        }

        return $body + ['stale' => false];
    }

    /**
     * Le corps contient-il le groupe `sensitive` d'un véhicule (et non la simple mention du groupe dans un lien) ?
     *
     * @param  array<string, mixed>  $body
     */
    private function carriesSensitiveGroup(array $body): bool
    {
        $data = $body['data'] ?? [];
        $items = is_array($data) && array_is_list($data) ? $data : [$data];

        foreach ($items as $item) {
            if (is_array($item) && array_key_exists('sensitive', $item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return list<Vehicle>
     */
    private function vehicles(?array $data): array
    {
        $stale = (bool) ($data['stale'] ?? false);

        return array_values(array_map(fn (array $item) => Vehicle::fromArray($item, $stale), (array) ($data['data'] ?? [])));
    }

    /**
     * @param  list<string>|null  $fields
     * @return array{query: array<string, string>}
     */
    private function query(?array $fields): array
    {
        return ['query' => $fields === null ? [] : ['fields' => implode(',', $fields)]];
    }

    /**
     * @return array<string, string>
     */
    private function readerHeaders(string $reader, ?string $readerRef): array
    {
        if (! in_array($reader, self::READER_KINDS, true)) {
            throw new IdentityRejected(400, 'The reader must be member or anonymous.', 'invalid_reader');
        }

        return array_filter(['X-Reader' => $reader, 'X-Reader-Ref' => $readerRef]);
    }

    /**
     * @param  list<string>|null  $fields
     */
    private function personKey(string $productToken, string $what, ?array $fields): string
    {
        $person = hash('sha256', $this->client->claimOf($productToken, 'sub').'|'.$this->client->claimOf($productToken, 'client_id'));

        return 'identity-connector:vehicles:'.$person.':'.$this->generation($person).':'.hash('sha256', $what.'|'.implode(',', $fields ?? []));
    }

    /**
     * @param  list<string>|null  $fields
     */
    private function serviceKey(string $what, string $reader, ?array $fields): string
    {
        return 'identity-connector:vehicles:service:'.hash('sha256', $reader.'|'.$what.'|'.implode(',', $fields ?? []));
    }

    /**
     * Invalide les lectures de cette personne : leur clé change.
     */
    private function forget(string $productToken): void
    {
        $person = hash('sha256', $this->client->claimOf($productToken, 'sub').'|'.$this->client->claimOf($productToken, 'client_id'));
        $this->cache->put('identity-connector:vehicles:generation:'.$person, $this->generation($person) + 1, $this->staleSeconds * 24);
    }

    private function generation(string $person): int
    {
        return (int) $this->cache->get('identity-connector:vehicles:generation:'.$person, 0);
    }
}
