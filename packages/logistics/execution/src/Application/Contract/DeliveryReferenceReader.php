<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Resuelve la referencia LEGIBLE de los envíos de una lista de entregas (pedido, cliente y dirección),
 * para que el chofer NUNCA vea un UUID en pantalla (regla de producto). La implementación lee la tabla
 * `shipments` en solo lectura por el query builder: Ejecución no se acopla al dominio de shipments
 * (mismo patrón que StopEnrichmentReader). Resuelve EN LOTE (whereIn) para evitar N+1.
 */
interface DeliveryReferenceReader
{
    /**
     * Mapa shipment_id → referencia legible. Un envío sin fila devuelve nulls; nunca lanza.
     *
     * @param  list<string>                                                                                         $shipmentIds
     * @return array<string, array{order_reference: string|null, customer_name: string|null, address: string|null}>
     */
    public function forShipmentIds(array $shipmentIds): array;
}
