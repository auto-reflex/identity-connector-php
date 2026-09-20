<?php

namespace AutoGteck\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * L'échéance est arrivée (AR-055, AR-056) : le produit **efface maintenant** les données locales de ce compte
 * (ou les anonymise selon ses obligations), puis appelle `Identity::client()->acknowledgeDeletion($userId)`.
 * Le connecteur n'accuse jamais à la place du produit. Si le traitement échoue, laissez l'exception remonter :
 * Identity renverra l'événement, donc l'effacement doit pouvoir être rejoué sans effet.
 */
class AccountDeletionDue
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
