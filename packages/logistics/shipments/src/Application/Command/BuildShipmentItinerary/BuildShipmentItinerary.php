<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\BuildShipmentItinerary;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de (re)construir el itinerario de un envío: decidir propia vs. tercerizado por el departamento
 * destino y armar la secuencia de tramos que recorre, persistiéndola.
 *
 * DTO inmutable de un solo primitivo (el id del envío). Es idempotente en el handler: reconstruye y
 * reemplaza, de modo que volver a despacharlo no duplica tramos.
 */
final readonly class BuildShipmentItinerary implements Command
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
