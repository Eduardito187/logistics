<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Queue;

use Logistics\Outbox\Application\Contract\OutboxMessageQueue;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Implementación del puerto de encolado sobre las colas de Laravel (Valkey). Encola un
 * DispatchOutboxMessageJob por mensaje a la cola que corresponde a su destino.
 *
 * El ruteo por cola aísla el head-of-line blocking (A-5/C-2): los avisos al cliente van a la cola
 * `notifications` y el resto a `outbox`, cada una con sus propios workers always-on. Así un destino lento
 * en una cola no frena a los destinos de la otra. El mapa prefijo→cola es configurable
 * (`logistics-outbox.pump.queue_routes`); el match es por prefijo más largo, como el RoutingMessageDispatcher.
 */
final readonly class LaravelOutboxMessageQueue implements OutboxMessageQueue
{
    /**
     * @param array<string, string> $queueRoutes  prefijo de event_name => nombre de cola
     * @param string                $defaultQueue cola para los eventos sin prefijo que matchee
     */
    public function __construct(
        private array $queueRoutes,
        private string $defaultQueue,
    ) {}

    public function enqueue(OutboxMessage $message): void
    {
        $queue = $this->queueFor($message->eventName());

        DispatchOutboxMessageJob::dispatch($message->id()->toString(), $queue);
    }

    /**
     * Elige la cola para un event_name: la del prefijo más largo que matchee, o la cola por defecto.
     */
    private function queueFor(string $eventName): string
    {
        $bestPrefix = null;
        foreach (array_keys($this->queueRoutes) as $prefix) {
            if (str_starts_with($eventName, $prefix)
                && ($bestPrefix === null || strlen($prefix) > strlen($bestPrefix))) {
                $bestPrefix = $prefix;
            }
        }

        return $bestPrefix === null ? $this->defaultQueue : $this->queueRoutes[$bestPrefix];
    }
}
