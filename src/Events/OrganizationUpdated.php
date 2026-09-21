<?php

namespace AutoGteck\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * L'identité légale d'une organisation a changé chez Identity (SIRET posé, changé ou retiré, AR-075). `$changed` : ce qui a changé
 * (`legal`). L'événement ne porte que l'identifiant : relisez l'organisation (`Identity::organization($id)`). Les organisations que
 * ce produit ne connaît pas sont à ignorer.
 */
class OrganizationUpdated
{
    use Dispatchable;

    /**
     * @param  list<string>  $changed
     */
    public function __construct(
        public readonly string $organizationId,
        public readonly array $changed,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
