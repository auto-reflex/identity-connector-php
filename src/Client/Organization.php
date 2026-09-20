<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Une organisation Identity vue par une personne : son rôle (`owner`, `admin`, `member`) et, pour le
 * détail, les membres (jamais leur email).
 */
final readonly class Organization
{
    /**
     * @param  list<OrganizationMember>|null  $members  `null` dans une liste, renseigné dans le détail
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $role,
        public ?array $members = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            slug: (string) $data['slug'],
            role: (string) $data['role'],
            members: isset($data['members']) && is_array($data['members'])
                ? array_values(array_map(fn (array $member) => OrganizationMember::fromArray($member), $data['members']))
                : null,
        );
    }
}
