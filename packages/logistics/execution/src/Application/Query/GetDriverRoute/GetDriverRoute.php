<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDriverRoute;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide la ruta del chofer de un camión: "mi ruta" del día. Si se da la fecha, la de ese día; si no, la
 * ruta abierta vigente (Planned o InProgress).
 */
final readonly class GetDriverRoute implements Query
{
    public function __construct(
        public string $vehicleId,
        public ?string $routeDate = null,
    ) {}
}
