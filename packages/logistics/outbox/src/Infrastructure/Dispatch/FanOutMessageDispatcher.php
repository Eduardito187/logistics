<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Dispatch;

use Illuminate\Contracts\Container\Container;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Dispatcher que ABANICA un mismo evento hacia VARIOS dispatchers (uno por contexto que reacciona).
 *
 * El RoutingMessageDispatcher rutea cada evento a UN solo adapter (el del prefijo más largo). Algunos
 * hechos, en cambio, disparan reacciones en varios contextos a la vez: p. ej. un incidente de camión
 * (execution.vehicle_incident_reported) deja el vehículo fuera de servicio (flota), replanifica los
 * envíos pendientes (planificación) y avisa a clientes y supervisor (notificaciones). Para esos, se
 * rutea el nombre exacto del evento a ESTE dispatcher, que entrega el mensaje a todos los adapters
 * configurados para ese evento.
 *
 * Los adapters se resuelven perezosamente del contenedor por su class-string (no se instancian hasta
 * que llega su evento). El orden del abanico es el de la config. Cada adapter debe ser idempotente: el
 * outbox reentrega at-least-once, así que un reintento del lote puede llamar al abanico otra vez.
 */
final readonly class FanOutMessageDispatcher implements MessageDispatcher
{
    /**
     * @param array<string, list<class-string<MessageDispatcher>>> $fanout eventName => lista de adapters
     */
    public function __construct(
        private Container $container,
        private array $fanout,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        $targets = $this->fanout[$message->eventName()] ?? [];

        foreach ($targets as $class) {
            $dispatcher = $this->container->make($class);
            assert($dispatcher instanceof MessageDispatcher);
            $dispatcher->dispatch($message);
        }
    }
}
