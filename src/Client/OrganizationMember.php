<?php

namespace AutoReflex\IdentityConnector\Client;

final readonly class OrganizationMember
{
    public function __construct(public string $userId, public ?string $name, public string $role) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['user_id'], isset($data['name']) ? (string) $data['name'] : null, (string) $data['role']);
    }
}
