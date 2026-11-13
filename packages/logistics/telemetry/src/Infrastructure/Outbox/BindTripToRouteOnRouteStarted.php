<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Telemetry\Application\Command\BindTripToRoute\BindTripToRoute;

/**
 * Reactor outbox (Fase 3.3): cuando el chofer INICIA la ruta del día (`execution.driver_route_started`), la
 * TELEMETRÍA liga el trip activo del vehículo a esa ruta (route_id), de modo que la traza GPS del viaje
 * quede asociada a la ruta de ejecución. Parte de la cadena automática publicación → ruta → salida → trip.
 *
 * Idempotente: el comando BindTripToRoute no hace nada si no hay trip activo o si ya estaba ligado. El
 * outbox reentrega at-least-once; una segunda entrega no reescribe el vínculo.
 */
final readonly class BindTripToRouteOnRouteStarted implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.driver_route_started') {
            return;
        }

        $payload = $message->payload();

        $vehicleId = $payload['vehicle_id'] ?? null;
        $routeId = $payload['route_id'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '' || !is_string($routeId) || $routeId === '') {
            return;
        }

        $this->commandBus->dispatch(new BindTripToRoute(
            vehicleId: $vehicleId,
            routeId: $routeId,
        ));
    }
}
