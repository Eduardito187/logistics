<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Outbox;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDrop;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Throwable;

/**
 * Reactor outbox: cuando la TIENDA concilia la recepción de un pedido en su nodo destino
 * (`execution.node_reception_reconciled`) y el chofer NO había registrado su «entrega en tienda», la registra
 * el sistema con la hora de la conciliación.
 *
 * Así hay UN solo hecho que dice "el pedido ya está en la tienda" (`execution.shipment_dropped_at_store`),
 * venga del chofer o de la tienda: de ese único hecho salen el «listo para retirar», el aviso al cliente y
 * el cierre de la parada del camión. Si el chofer se olvidó de cerrar su parada, el cliente igual se entera
 * y la ruta no queda con una parada colgada.
 *
 * Idempotente (la entrega en tienda se registra una sola vez) y tolerante: si el nodo no es el destino del
 * envío, si no hay entrega ni ruta, o si la entrega ya está cerrada, no hace nada.
 */
final readonly class StoreDropOnReceptionReconciled implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private ConnectionResolverInterface $connections,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.node_reception_reconciled') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = is_string($payload['shipment_id'] ?? null) ? $payload['shipment_id'] : '';
        $nodeId = is_string($payload['destination_node_id'] ?? null) ? $payload['destination_node_id'] : '';
        if ($shipmentId === '' || $nodeId === '') {
            return;
        }

        try {
            $destination = $this->connections->connection()->table('shipments')->where('id', $shipmentId)->value('destination_node_id');
        } catch (Throwable) {
            return;
        }

        // Una recepción en un nodo de paso (no es el destino final del envío) no es la llegada a su tienda.
        if (!is_string($destination) || $destination !== $nodeId) {
            return;
        }

        try {
            $this->commandBus->dispatch(new RecordStoreDrop(
                $shipmentId,
                $nodeId,
                $message->createdAt()->format(DATE_ATOM),
            ));
        } catch (ExecutionException) {
            // Sin entrega ni ruta, o entrega ya cerrada: no hay nada que registrar.
        }
    }
}
