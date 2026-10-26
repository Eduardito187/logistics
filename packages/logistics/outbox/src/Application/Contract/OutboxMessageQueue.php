<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Contract;

use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Puerto de encolado del outbox: entrega un mensaje ya tomado del store a la cola de workers que lo
 * despachará en paralelo (C-2 de la auditoría de throughput).
 *
 * La implementación (Infrastructure) decide la cola concreta según el destino del mensaje (p. ej.
 * `notifications` para los avisos al cliente, `outbox` para el resto), de modo que un destino lento/caído
 * no bloquee a los demás (head-of-line blocking). Mantener el despacho idempotente es responsabilidad del
 * store (clave event_id) y de los consumidores (dedupe por eventId): reencolar un mensaje es inocuo.
 */
interface OutboxMessageQueue
{
    public function enqueue(OutboxMessage $message): void;
}
