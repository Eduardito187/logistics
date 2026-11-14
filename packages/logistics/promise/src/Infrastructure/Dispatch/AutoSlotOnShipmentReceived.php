<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Dispatch;

/**
 * Reactor shipments → promise (Módulo 1, Fase 1.1): ante `shipments.shipment_received`, si el envío recién
 * ingresado (de Magento/POS u otra fuente) trae una fecha de entrega sin ventana, le auto-asigna la franja.
 * Cubre los pedidos que entran ya con fecha fuera del flujo de pago (no cada orden pasa por `orders.order_paid`).
 *
 * El id del envío viaja como `shipment_id` en el payload (o el aggregateId del evento, que es el id del
 * envío).
 */
final readonly class AutoSlotOnShipmentReceived extends AutoSlotDispatcher
{
    protected function eventName(): string
    {
        return 'shipments.shipment_received';
    }

    protected function payloadIdKey(): string
    {
        return 'shipment_id';
    }
}
