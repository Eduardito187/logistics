<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain\Contract;

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;

/**
 * Puerto de lectura puntual de un mensaje del outbox por su id. Lo usa el job encolado por mensaje (C-2)
 * para releer el estado más fresco antes de despacharlo, sin bloquear la fila.
 *
 * Se separa de OutboxStore (append/pullBatch/save) a propósito: es una capacidad distinta (lookup por id)
 * y así los dobles de test de OutboxStore de otros contextos no quedan obligados a implementarla.
 */
interface OutboxMessageFinder
{
    public function find(OutboxMessageId $id): ?OutboxMessage;
}
