<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\GetDeparture;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide una salida concreta por su id.
 */
final readonly class GetDeparture implements Query
{
    public function __construct(
        public string $departureId,
    ) {}
}
