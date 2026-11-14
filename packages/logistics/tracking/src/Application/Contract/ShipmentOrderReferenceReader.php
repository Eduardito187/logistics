<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

/**
 * Puerto de lectura CROSS-CONTEXT: la referencia de la orden (orderReference) de un envío. Sirve para
 * ABRIR el seguimiento de un envío que todavía no tiene fila en tracking (se perdieron
 * `orders.order_placed` / `shipments.shipment_received` con el outbox congelado, o el envío entró fuera del
 * flujo). El seguimiento no debe acoplarse al dominio de shipments, así que la infraestructura lo lee del
 * lado lectura (tabla `shipments`) en solo lectura. Null si el envío no existe o no tiene referencia.
 */
interface ShipmentOrderReferenceReader
{
    public function orderReferenceForShipment(string $shipmentId): ?string;
}
