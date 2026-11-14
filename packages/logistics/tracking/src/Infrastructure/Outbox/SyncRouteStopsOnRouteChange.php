<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\SyncRouteStops\SyncRouteStops;

/**
 * Reactor outbox: cuando la ruta del camión cambia (se arma, se le suma o se le quita una parada), alinea el
 * seguimiento de sus envíos: posición en la ruta y paradas pendientes por delante. Sin esto el pedido no
 * mostraba «a N paradas» hasta entregarse.
 *
 * Idempotente (el handler recalcula desde la tabla de paradas) y tolerante: sin `route_id` no hace nada.
 */
final readonly class SyncRouteStopsOnRouteChange implements MessageDispatcher
{
    private const EVENTS = [
        'execution.driver_route_assembled',
        'execution.route_stop_attached',
        'execution.route_stop_detached',
    ];

    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if (!in_array($message->eventName(), self::EVENTS, true)) {
            return;
        }

        $payload = $message->payload();
        $routeId = is_string($payload['route_id'] ?? null) ? $payload['route_id'] : $message->aggregateId();
        if ($routeId === '') {
            return;
        }

        $detached = $message->eventName() === 'execution.route_stop_detached' && is_string($payload['shipment_id'] ?? null)
            ? $payload['shipment_id']
            : null;

        $this->commandBus->dispatch(new SyncRouteStops($routeId, $detached));
    }
}
