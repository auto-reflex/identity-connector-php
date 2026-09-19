<?php

namespace AutoReflex\IdentityConnector\Profiles;

/**
 * Ce qu'Identity dit d'une personne (`/userinfo`), selon les scopes du token. `null` = donnée non consentie.
 */
final readonly class IdentityUser
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        public ?bool $emailVerified,
        public ?string $locale,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public static function fromUserInfo(array $claims): self
    {
        return new self(
            id: (string) $claims['sub'],
            name: isset($claims['name']) ? (string) $claims['name'] : null,
            email: isset($claims['email']) ? (string) $claims['email'] : null,
            emailVerified: isset($claims['email_verified']) ? (bool) $claims['email_verified'] : null,
            locale: isset($claims['locale']) ? (string) $claims['locale'] : null,
        );
    }
}
