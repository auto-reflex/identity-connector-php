<?php

namespace AutoGteck\IdentityConnector\Client;

use Carbon\CarbonImmutable;

/**
 * Identité légale d'une organisation (AR-075) : le SIRET, vérifié une fois par Identity auprès de Sirene. Un produit qui facture la
 * copie sur chaque facture émise (un SIRET change, une facture émise ne change pas) ; un produit qui publie exige `verified`.
 */
final readonly class LegalIdentity
{
    /**
     * @param  string  $registry  registre qui a vérifié (`fr_sirene`)
     * @param  array{line: string, postal_code: string, city: string}|null  $address
     */
    public function __construct(
        public string $registry,
        public string $siret,
        public string $siren,
        public ?string $legalName,
        public ?string $form,
        public ?array $address,
        public ?string $nafCode,
        public bool $verified,
        public ?CarbonImmutable $verifiedAt,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null || ! isset($data['siret'])) {
            return null;
        }

        return new self(
            registry: (string) ($data['registry'] ?? 'fr_sirene'),
            siret: (string) $data['siret'],
            siren: (string) ($data['siren'] ?? substr((string) $data['siret'], 0, 9)),
            legalName: isset($data['legal_name']) ? (string) $data['legal_name'] : null,
            form: isset($data['form']) ? (string) $data['form'] : null,
            address: isset($data['address']) && is_array($data['address'])
                ? ['line' => (string) ($data['address']['line'] ?? ''), 'postal_code' => (string) ($data['address']['postal_code'] ?? ''), 'city' => (string) ($data['address']['city'] ?? '')]
                : null,
            nafCode: isset($data['naf_code']) ? (string) $data['naf_code'] : null,
            verified: (bool) ($data['verified'] ?? false),
            verifiedAt: isset($data['verified_at']) ? CarbonImmutable::parse((string) $data['verified_at']) : null,
        );
    }
}
