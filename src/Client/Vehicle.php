<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Un véhicule tel qu'Identity l'autorise à ce demandeur (AR-059) : seuls les groupes autorisés et demandés sont présents
 * (`identity`, `specs`, `media`, `usage`, `sensitive`). Une donnée absente n'est pas « vide » : elle n'est pas accordée.
 * `owner`, `role` et `link` ne sont renseignés que pour la personne propriétaire (ou son organisation).
 */
final class Vehicle
{
    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array{type: string, id: string}|null  $owner
     */
    public function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly array $groups,
        public readonly ?array $owner = null,
        public readonly ?string $role = null,
        public readonly ?VehicleLink $link = null,
        public readonly bool $stale = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, bool $stale = false): self
    {
        $groups = array_intersect_key($data, array_flip(['identity', 'specs', 'media', 'usage', 'sensitive']));

        return new self(
            (string) $data['id'],
            (int) $data['version'],
            array_map(fn ($group) => (array) $group, $groups),
            isset($data['owner']) && is_array($data['owner']) ? ['type' => (string) $data['owner']['type'], 'id' => (string) $data['owner']['id']] : null,
            isset($data['role']) ? (string) $data['role'] : null,
            isset($data['link']) && is_array($data['link']) ? VehicleLink::fromArray($data['link']) : null,
            $stale,
        );
    }

    public function has(string $group): bool
    {
        return isset($this->groups[$group]);
    }

    /**
     * @return array<string, mixed>|null `null` si le groupe n'est pas accordé
     */
    public function group(string $group): ?array
    {
        return $this->groups[$group] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function identity(): ?array
    {
        return $this->group('identity');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function specs(): ?array
    {
        return $this->group('specs');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function media(): ?array
    {
        return $this->group('media');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function usage(): ?array
    {
        return $this->group('usage');
    }

    /**
     * VIN et plaque : jamais mis en cache, à ne pas conserver. Présent seulement à la personne propriétaire qui l'a demandé.
     *
     * @return array<string, mixed>|null
     */
    public function sensitive(): ?array
    {
        return $this->group('sensitive');
    }

    /**
     * Libellé prêt à afficher (« Peugeot 205 GTI (1991) ») avec ce qui est accordé.
     */
    public function label(): string
    {
        $identity = $this->identity() ?? [];

        return trim(implode(' ', array_filter([$identity['make'] ?? null, $identity['model'] ?? null, $identity['trim'] ?? null, isset($identity['year']) ? '('.$identity['year'].')' : null])));
    }
}
