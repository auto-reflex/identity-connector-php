<?php

namespace AutoReflex\IdentityConnector\Events;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Identity a levé la suspension de ce compte (AR-053).
 */
class AccountReinstated
{
    use Dispatchable;

    public function __construct(
        public readonly string $userId,
        public readonly string $eventId,
        public readonly CarbonImmutable $occurredAt,
    ) {}
}
