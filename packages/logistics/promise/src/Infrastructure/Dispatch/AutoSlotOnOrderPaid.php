<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Dispatch;

/**
 * Reactor orders → promise (Módulo 1, Fase 1.1): ante `orders.order_paid`, si el envío ya creado para la
 * orden trae una fecha de entrega sin ventana, le auto-asigna la franja. Va en el abanico de `order_paid`
 * DESPUÉS del reactor que crea el envío (OrderPaidShipmentDispatcher), para que el envío exista al leer su
 * fecha pendiente.
 *
 * El id del envío/pedido viaja como `order_id` en el payload (o el aggregateId del evento), el mismo con el
 * que el puerto hacia shipments resuelve el envío de la orden.
 */
final readonly class AutoSlotOnOrderPaid extends AutoSlotDispatcher
{
    protected function eventName(): string
    {
        return 'orders.order_paid';
    }

    protected function payloadIdKey(): string
    {
        return 'order_id';
    }
}
