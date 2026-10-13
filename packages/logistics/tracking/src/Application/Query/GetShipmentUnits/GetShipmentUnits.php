<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Query\GetShipmentUnits;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta interna del detalle per-bulto del seguimiento de un envío. Devuelve una lista de
 * UnitTrackingView (posiblemente vacía si el envío todavía no tiene bultos proyectados).
 */
final readonly class GetShipmentUnits implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
