<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDeliveryState;

use Logistics\Shared\Application\Query\Query;

/**
 * Devuelve el estado actual de la entrega de un envío (con sus escaneos). Null si todavía no se abrió.
 */
final readonly class GetDeliveryState implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
