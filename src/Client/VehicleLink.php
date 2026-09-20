<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Le lien d'un véhicule avec ce produit : les groupes que le produit lit, la visibilité choisie par le propriétaire.
 */
final readonly class VehicleLink
{
    /**
     * @param  list<string>  $groups
     */
    public function __construct(
        public string $product,
        public array $groups,
        public string $visibility,
        public bool $shareUsage,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['product'] ?? ''),
            array_values(array_map('strval', (array) ($data['groups'] ?? []))),
            (string) ($data['visibility'] ?? 'private'),
            (bool) ($data['share_usage'] ?? false),
        );
    }
}
