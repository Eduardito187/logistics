<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\EnqueueOutbox;

/**
 * Resumen de una corrida del dispatcher (pump): cuántos mensajes se tomaron y se encolaron a los workers.
 */
final readonly class EnqueueOutboxResult
{
    public function __construct(
        public int $enqueued,
    ) {}
}
