<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipments;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista envíos, opcionalmente filtrados por estado y/o fuente (ambos como el valor del enum, o null).
 */
final readonly class ListShipments implements Query
{
    public function __construct(
        public ?string $status = null,
        public ?string $source = null,
    ) {}
}
