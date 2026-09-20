<?php

namespace AutoReflex\IdentityConnector\Client;

/**
 * Une page du garage d'une personne : les véhicules et le curseur de la suivante (`null` à la fin).
 */
final readonly class VehiclePage
{
    /**
     * @param  list<Vehicle>  $vehicles
     */
    public function __construct(public array $vehicles, public ?string $nextCursor = null) {}
}
