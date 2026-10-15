<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Dispatch;

use Illuminate\Contracts\Container\Container;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Dispatcher que enruta cada mensaje hacia el adapter de su canal según el nombre del evento.
 *
 * El relay inyecta ESTE dispatcher; él decide el destino real por el prefijo del event_name
 * (p. ej. "sap.", "notifications.fcm.") y delega en el adapter correspondiente. Los adapters reales
 * los aportan los otros contextos/conectores (SAP, FCM, email, SMS, webhooks); acá solo se rutea.
 *
 * El match es por prefijo más largo: así "notifications.fcm." gana sobre "notifications." si ambos
 * están registrados. Si ningún prefijo matchea, cae al dispatcher por defecto (hoy NullMessageDispatcher).
 *
 * Los adapters se resuelven perezosamente del contenedor por su class-string: no se instancian hasta
 * que llega un mensaje de su canal, y un canal sin adapter configurado no rompe el arranque.
 */
final readonly class RoutingMessageDispatcher implements MessageDispatcher
{
    /**
     * @param array<string, class-string<MessageDispatcher>> $routes            prefijo de event_name => adapter
     * @param class-string<MessageDispatcher>                $defaultDispatcher adapter catch-all
     */
    public function __construct(
        private Container $container,
        private array $routes,
        private string $defaultDispatcher,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        $this->resolve($message->eventName())->dispatch($message);
    }

    /**
     * Elige el adapter para un event_name: el del prefijo más largo que matchee, o el por defecto.
     */
    private function resolve(string $eventName): MessageDispatcher
    {
        $bestPrefix = null;
        foreach (array_keys($this->routes) as $prefix) {
            if (str_starts_with($eventName, $prefix)
                && ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix))) {
                $bestPrefix = $prefix;
            }
        }

        $class = $bestPrefix === null ? $this->defaultDispatcher : $this->routes[$bestPrefix];

        $dispatcher = $this->container->make($class);
        assert($dispatcher instanceof MessageDispatcher);

        return $dispatcher;
    }
}
