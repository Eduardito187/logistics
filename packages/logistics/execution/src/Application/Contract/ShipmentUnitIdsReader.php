<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Resuelve los BULTOS (shipment_units) de cada envío, en lote y en orden estable. Es la fuente de
 * `units_total` y `unit_ids` de la parada y de la entrega: el total esperado del POD se cuenta en bultos,
 * NUNCA sumando cantidades de producto (cantidad de producto ≠ cantidad de bultos).
 *
 * Solo lectura y en primitivos: Ejecución no se acopla al dominio de shipments.
 */
interface ShipmentUnitIdsReader
{
    /**
     * @param  list<string>                $shipmentIds
     * @return array<string, list<string>> mapa id de envío => ids de sus bultos (ausente si no tiene)
     */
    public function forShipmentIds(array $shipmentIds): array;
}
