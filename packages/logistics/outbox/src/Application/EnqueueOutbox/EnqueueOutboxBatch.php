<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\EnqueueOutbox;

use Logistics\Outbox\Application\Contract\OutboxMessageQueue;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Dispatcher del outbox (C-2): toma un lote de mensajes despachables y encola UN job por mensaje a los
 * workers always-on, en vez de despachar el I/O externo inline y secuencial como hacía el relay.
 *
 * Por qué así: el pull del store usa FOR UPDATE SKIP LOCKED + lease, de modo que varios pumps y varios
 * workers trabajan en paralelo sin pisarse. Separar el "tomar y encolar" (barato, sin I/O) del "despachar
 * al destino externo" (lento) elimina el techo de ~100 msg/min y el head-of-line blocking: un destino
 * caído ya no frena al resto del lote, solo ocupa su propio worker (y el circuit breaker corta rápido).
 *
 * El lease del pull protege de doble-encolado dentro de su ventana; si aun así un mensaje se reencola
 * (lease vencido con backlog), el despacho es idempotente (event_id + consumidores que se desduplican),
 * así que reprocesar es inocuo.
 */
final readonly class EnqueueOutboxBatch
{
    public function __construct(
        private OutboxStore $store,
        private OutboxMessageQueue $queue,
        private Clock $clock,
    ) {}

    /**
     * Toma hasta $limit mensajes despachables y los encola. Devuelve cuántos se encolaron.
     */
    public function handle(int $limit): EnqueueOutboxResult
    {
        $now = $this->clock->now();
        $messages = $this->store->pullBatch($limit, $now);

        foreach ($messages as $message) {
            $this->queue->enqueue($message);
        }

        return new EnqueueOutboxResult(count($messages));
    }
}
