<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use DateTimeImmutable;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Tracking\Application\Command\MarkShipmentAtStore\MarkShipmentAtStore;
use Throwable;

/**
 * Reactor outbox: cuando el chofer deja en la tienda un pedido que el cliente retira ahí
 * (`execution.shipment_dropped_at_store`), el seguimiento del cliente refleja que YA LLEGÓ a la tienda.
 *
 * Aplica con la MISMA condición que el aviso «listo para recojo» de notificaciones: pedido de cliente con
 * retiro en tienda (`delivery_mode = store_pickup`) y el nodo del hecho es SU tienda destino. Un traspaso
 * interno o un nodo de paso no marcan nada.
 *
 * No cambia el estado visible: despacha {@see MarkShipmentAtStore}, que fija `at_store_at` una sola vez.
 * Tolerante en la lectura: sin envío o con la consulta caída no hace nada. Un fallo al GUARDAR la marca sí
 * se propaga para que el outbox reintente (va último en su abanico y el aviso al cliente es idempotente).
 */
final readonly class AtStoreOnStoreDrop implements MessageDispatcher
{
    private const string EVENT = 'execution.shipment_dropped_at_store';

    private const string STORE_PICKUP = 'store_pickup';

    public function __construct(
        private CommandBus $commandBus,
        private QueryBus $queryBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== self::EVENT) {
            return;
        }

        $payload = $message->payload();
        $shipmentId = is_string($payload['shipment_id'] ?? null) ? $payload['shipment_id'] : '';
        $nodeId = is_string($payload['destination_node_id'] ?? null) ? $payload['destination_node_id'] : '';
        if ($shipmentId === '' || $nodeId === '') {
            return;
        }

        try {
            $shipment = $this->queryBus->ask(new FindShipment($shipmentId));
        } catch (Throwable) {
            // Sin el envío no se puede decidir si aplica (mismo criterio que el aviso al cliente).
            return;
        }

        if (!$shipment instanceof ShipmentView
            || $shipment->deliveryMode !== self::STORE_PICKUP
            || $shipment->destinationNodeId !== $nodeId) {
            return;
        }

        // Un fallo al guardar SÍ se propaga: el outbox reintenta el mensaje y la marca no se pierde. Es
        // seguro porque va último en su abanico y el aviso al cliente es idempotente por (envío, tipo).
        $this->commandBus->dispatch(new MarkShipmentAtStore(
            shipmentId: $shipmentId,
            occurredAt: $this->occurredAt($message),
        ));
    }

    /**
     * Momento del hecho. El mensaje del outbox no persiste el `occurredAt` del evento de dominio, así que
     * se prefiere un `occurred_at` explícito del payload si el emisor lo trae; si no, el `created_at` del
     * mensaje, que se graba en la MISMA transacción que la entrega en tienda.
     */
    private function occurredAt(OutboxMessage $message): string
    {
        $value = $message->payload()['occurred_at'] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $message->createdAt()->format(DateTimeImmutable::ATOM);
    }
}
