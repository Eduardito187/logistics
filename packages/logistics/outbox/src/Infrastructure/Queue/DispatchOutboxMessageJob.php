<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Logistics\Outbox\Application\RelayOutbox\ProcessOutboxMessage;
use Logistics\Outbox\Domain\Contract\OutboxMessageFinder;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Job que despacha UN mensaje del outbox a su destino externo, corriendo en los workers always-on
 * (C-2 de la auditoría). El dispatcher (`logistics:outbox:pump`) encola uno de estos por cada mensaje del
 * lote; al estar en colas con `numprocs>1`, los workers despachan en paralelo.
 *
 * Lleva solo el id del mensaje (primitivo, serializable): en handle() lo relee del store para operar
 * sobre el estado más fresco. El despacho real, el backoff y el dead-letter los aplica
 * ProcessOutboxMessage, EXACTAMENTE la misma política que el relay sincrónico.
 *
 * Idempotencia: si el mensaje ya no existe o ya está en estado terminal (otro worker lo despachó entre el
 * pull y este job), ProcessOutboxMessage no hace nada. El reintento del job lo decide ProcessOutboxMessage
 * reprogramando available_at en el store, no el reintento de cola de Laravel: por eso $tries = 1 (un fallo
 * de despacho NO debe reencolar el job; el mensaje ya quedó reprogramado con su backoff en la base).
 */
final class DispatchOutboxMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Un solo intento de cola: la política de reintentos/backoff vive en el store (available_at), no en
     * la cola. Reencolar el job duplicaría el reintento.
     */
    public int $tries = 1;

    public function __construct(
        private readonly string $messageId,
        string $queue,
    ) {
        $this->onQueue($queue);
    }

    public function handle(OutboxMessageFinder $finder, ProcessOutboxMessage $processor, Clock $clock): void
    {
        $message = $finder->find(OutboxMessageId::fromString($this->messageId));
        if ($message === null) {
            return;
        }

        $processor->process($message, $clock->now());
    }
}
