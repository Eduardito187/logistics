<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Lee la dimensión de propiedad/fulfillment de un envío (persistida en shipments por otro contexto) para
 * que la ejecución pueda BIFURCAR su flujo por `fulfillment_mode` sin acoplarse al dominio de shipments.
 *
 * La implementación lee la tabla read-only (query builder), igual que StopEnrichmentReader: cero
 * dependencia composer entre contextos. Tolerante a datos ausentes: un envío sin fila conocida degrada a
 * OWNED (retail), el comportamiento histórico.
 */
interface ShipmentFulfillmentReader
{
    public function forShipmentId(string $shipmentId): Ownership;
}
