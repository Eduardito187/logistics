<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetShipmentTracking;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta interna del seguimiento por id de envío. Devuelve un ShipmentTrackingView o null si el
 * envío todavía no tiene seguimiento abierto.
 */
final readonly class GetShipmentTracking implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
