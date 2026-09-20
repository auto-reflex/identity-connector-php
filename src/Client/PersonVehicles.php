<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Les véhicules vus par la personne de la requête en cours : `Identity::vehicles()`. Chaque appel porte son token produit.
 * Voir {@see VehicleClient} pour le détail, dont les erreurs.
 */
final class PersonVehicles
{
    public function __construct(private readonly VehicleClient $client, private readonly string $productToken) {}

    /**
     * @param  list<string>|null  $fields
     */
    public function get(string $id, ?array $fields = null): ?Vehicle
    {
        return $this->client->get($this->productToken, $id, $fields);
    }

    /**
     * @param  list<string>  $ids
     * @param  list<string>|null  $fields
     * @return list<Vehicle>
     */
    public function many(array $ids, ?array $fields = null): array
    {
        return $this->client->many($this->productToken, $ids, $fields);
    }

    /**
     * @param  list<string>|null  $fields
     */
    public function garage(?array $fields = null, ?string $owner = null, int $limit = 50, ?string $after = null): VehiclePage
    {
        return $this->client->garage($this->productToken, $fields, $owner, $limit, $after);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Vehicle
    {
        return $this->client->create($this->productToken, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $id, array $data, int $version, bool $confirmDecrease = false): Vehicle
    {
        return $this->client->update($this->productToken, $id, $data, $version, $confirmDecrease);
    }

    public function delete(string $id): void
    {
        $this->client->delete($this->productToken, $id);
    }

    /**
     * @param  list<string>  $groups
     */
    public function link(string $id, array $groups, string $visibility = 'private', bool $shareUsage = false): VehicleLink
    {
        return $this->client->link($this->productToken, $id, $groups, $visibility, $shareUsage);
    }

    public function unlink(string $id): void
    {
        $this->client->unlink($this->productToken, $id);
    }
}
