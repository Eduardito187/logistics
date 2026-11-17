<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentRoutingFacts;

/**
 * Lado de LECTURA (CQRS) de los datos de ruteo de un envío (extremos + coordenada del cliente), que alimenta
 * la construcción del itinerario. Lee la fila del envío sin reconstituir el agregado. El contrato vive en
 * Application; la implementación con query builder vive en Infrastructure.
 */
interface ShipmentRoutingFactsReader
{
    /**
     * Datos de ruteo del envío, o null si no existe.
     */
    public function factsFor(string $shipmentId): ?ShipmentRoutingFacts;
}
