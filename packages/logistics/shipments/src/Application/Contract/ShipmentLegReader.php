<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentLegView;

/**
 * Lado de LECTURA (CQRS) de los tramos de un envío: construye los {@see ShipmentLegView} directamente
 * desde las filas, SIN reconstituir el agregado. El contrato vive en Application (devuelve un ReadModel de
 * Application); la implementación con query builder vive en Infrastructure, de modo que el Domain queda
 * intacto. Lo consumirán las olas de UI/mapas (recorrido por tramos de un envío).
 */
interface ShipmentLegReader
{
    /**
     * Lista los tramos de un envío, ordenados por `seq` (orden del recorrido). Devuelve [] si el envío
     * todavía no tiene tramos.
     *
     * @return list<ShipmentLegView>
     */
    public function forShipment(string $shipmentId): array;
}
