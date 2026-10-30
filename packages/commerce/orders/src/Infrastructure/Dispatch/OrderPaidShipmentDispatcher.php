<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Dispatch;

use Commerce\Orders\Application\Command\MarkOrderFulfilling\MarkOrderFulfilling;
use Commerce\Orders\Domain\Contract\OrderRepository;
use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderId;
use Commerce\Orders\Domain\ValueObject\OrderLine;
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

        $this->commandBus->dispatch(new ReceiveShipment(
            source: self::SHIPMENT_SOURCE,
            externalId: $order->id()->toString(),
            customerName: $shipTo->customerName,
            addressText: $shipTo->addressText,
            addressReference: $shipTo->reference,
            lines: $this->shipmentLines($order),
            customerPhone: $shipTo->phone,
            customerEmail: $shipTo->email,
        ));

        $this->commandBus->dispatch(new MarkOrderFulfilling($order->id()->toString()));
    }

    /**
     * @return list<ShipmentLineInput>
     */
    private function shipmentLines(Order $order): array
    {
        return array_values(array_map(
            static fn (OrderLine $line): ShipmentLineInput => new ShipmentLineInput(
                sku: $line->sku,
                productName: $line->productName,
                quantity: $line->quantity,
                price: $line->unitPriceCents / 100,
                currency: $order->currency(),
            ),
            $order->lines(),
        ));
    }
}
