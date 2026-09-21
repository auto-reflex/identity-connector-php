<?php

namespace AutoGteck\IdentityConnector\Client;

/**
 * Organisation créée par ce produit pour un futur propriétaire (AR-070), avec l'identité légale vérifiée par Identity (AR-075).
 */
final readonly class ProvisionedOrganization
{
    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    /**
     * @param  string  $state  `pending` (invitation envoyée), `expired` (lien périmé : rappeler `provisionOrganization`) ou `active`
     *                         (le propriétaire a rejoint)
     */
    public function __construct(
        public string $organizationId,
        public string $slug,
        public string $reference,
        public string $state,
        public ?string $ownerUserId = null,
        public ?LegalIdentity $legal = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['organization_id'],
            (string) $data['slug'],
            (string) $data['reference'],
            (string) $data['state'],
            isset($data['owner_user_id']) ? (string) $data['owner_user_id'] : null,
            LegalIdentity::fromArray(isset($data['legal']) && is_array($data['legal']) ? $data['legal'] : null),
        );
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }
}
