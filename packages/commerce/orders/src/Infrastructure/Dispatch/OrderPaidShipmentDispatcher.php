<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Dispatch;

use Commerce\Orders\Application\Command\MarkOrderFulfilling\MarkOrderFulfilling;
use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;

/**
 * Puente orders → logística (OMS → Shipment, ADR 018): cuando una orden se paga (`orders.order_paid`),
 * crea el envío reutilizando el contexto `shipments` y pasa la orden a Fulfilling.
 *
 * Adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): es el único punto que conoce a
 * `shipments`, y lo alcanza por el CommandBus (no llama a su dominio). El dominio de `orders` no sabe
 * que existe `shipments`. Al extraer comercio a un servicio, este adaptador pasa a ser una llamada de red.
 *
 * Idempotencia (el outbox reentrega at-least-once):
 *  - `ReceiveShipment` es idempotente por (source, externalId) = ('commerce', orderId): reintentos no
 *    crean un envío nuevo.
 *  - `MarkOrderFulfilling` es idempotente en el agregado (no re-graba si el envío ya se solicitó).
 */
final readonly class OrderPaidShipmentDispatcher implements MessageDispatcher
{
    /** Fuente con la que se ingresan al contexto de envíos las órdenes del comercio. */
    private const SHIPMENT_SOURCE = 'commerce';

    public function __construct(
        private OrderRepository $orders,
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_paid') {
            return;
        }

        $order = $this->orders->findById(OrderId::fromString($message->aggregateId()));
        if ($order === null) {
            return;
        }

        $shipTo = $order->shipTo();
        $orderId = $order->id()->toString();

        // Multishipping: una orden puede repartirse a varios destinos (unas líneas a domicilio, otras a
        // retiro en tienda/CD). Se agrupan las líneas por destino y nace UN envío por grupo, todos bajo la
        // misma orden (orderReference). Si todas las líneas van al mismo destino (caso normal), es un solo
        // envío con externalId = orderId: comportamiento idéntico al anterior.
        foreach ($this->groupLinesByDestination($order) as $group) {
            $this->commandBus->dispatch(new ReceiveShipment(
                source: self::SHIPMENT_SOURCE,
                externalId: $group['externalId'],
                customerName: $shipTo->customerName,
                addressText: $shipTo->addressText,
                addressReference: $shipTo->reference,
                lines: $group['lines'],
                customerPhone: $shipTo->phone,
                customerEmail: $shipTo->email,
                destinationNodeId: $group['destinationNodeId'],
                orderReference: $orderId,
            ));
        }

        $this->commandBus->dispatch(new MarkOrderFulfilling($orderId));
    }

    /**
     * Agrupa las líneas de la orden por destino (null = domicilio; UUID = nodo de retiro). El grupo
     * domicilio conserva externalId = orderId (idempotencia y retrocompatibilidad); cada grupo-nodo lleva
     * externalId = "{orderId}#n:{nodeId}" (único por envío, hermano de la misma orden).
     *
     * @return list<array{externalId: string, destinationNodeId: string|null, lines: list<ShipmentLineInput>}>
     */
    private function groupLinesByDestination(Order $order): array
    {
        $orderId = $order->id()->toString();

        /** @var array<string, list<ShipmentLineInput>> $byDestination clave '' = domicilio */
        $byDestination = [];
        foreach ($order->lines() as $line) {
            $key = $line->destinationNodeId ?? '';
            $byDestination[$key][] = new ShipmentLineInput(
                sku: $line->sku,
                productName: $line->productName,
                quantity: $line->quantity,
                price: $line->unitPriceCents / 100,
                currency: $order->currency(),
            );
        }

        $groups = [];
        foreach ($byDestination as $destinationNodeId => $lines) {
            $isHome = $destinationNodeId === '';
            $groups[] = [
                'externalId' => $isHome ? $orderId : $orderId.'#n:'.$destinationNodeId,
                'destinationNodeId' => $isHome ? null : $destinationNodeId,
                'lines' => $lines,
            ];
        }

        return $groups;
    }
}
