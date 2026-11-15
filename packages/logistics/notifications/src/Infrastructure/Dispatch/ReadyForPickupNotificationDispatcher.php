<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Dispatch;

use Logistics\Notifications\Application\Command\QueueNotification\QueueNotification;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Throwable;

/**
 * Reactor outbox: cuando el CHOFER deja en la tienda un pedido que el cliente retira ahí
 * (`execution.shipment_dropped_at_store`), le avisa al cliente que su pedido llegó y puede pasar a recogerlo
 * (tipo de mensaje «listo para recojo»). Decisión del dueño: el aviso lo dispara la entrega del chofer.
 *
 * Es el ÚNICO disparador. Si el chofer no registró su paso y la tienda concilia la recepción, execution
 * registra la entrega en tienda por él (StoreDropOnReceptionReconciled) y emite este mismo hecho: el cliente
 * igual se entera, por el mismo camino.
 *
 * Solo aplica a un pedido de cliente con retiro en tienda (`delivery_mode = store_pickup`) en SU tienda
 * destino: un traspaso interno o un nodo de paso no avisan. Un aviso por pedido:
 * idempotente por (envío, tipo), así un reintento del outbox o una segunda conciliación no lo repiten.
 * Tolerante: sin envío, sin contacto o con la consulta caída no hace nada.
 */
final readonly class ReadyForPickupNotificationDispatcher implements MessageDispatcher
{
    private const EVENT = 'execution.shipment_dropped_at_store';

    private const STORE_PICKUP = 'store_pickup';

    public function __construct(
        private NotificationRepository $notifications,
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

        if ($this->notifications->existsForShipmentAndType($shipmentId, MessageType::ReadyForPickup)) {
            return;
        }

        try {
            $shipment = $this->queryBus->ask(new FindShipment($shipmentId));
        } catch (Throwable) {
            return;
        }

        if (!$shipment instanceof ShipmentView
            || $shipment->deliveryMode !== self::STORE_PICKUP
            || $shipment->destinationNodeId !== $nodeId) {
            return;
        }

        $email = $shipment->customerEmail !== null && trim($shipment->customerEmail) !== '' ? $shipment->customerEmail : null;
        $phone = $shipment->customerPhone !== null && trim($shipment->customerPhone) !== '' ? $shipment->customerPhone : null;
        if ($email === null && $phone === null) {
            return;
        }

        $this->commandBus->dispatch(new QueueNotification(
            $shipmentId,
            MessageType::ReadyForPickup->value,
            $email,
            $phone,
        ));
    }
}
