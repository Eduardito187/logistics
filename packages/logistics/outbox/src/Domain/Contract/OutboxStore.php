<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain\Contract;

use DateTimeImmutable;
use Logistics\Outbox\Domain\OutboxMessage;

/**
 * Puerto de persistencia del outbox. La implementación vive en Infrastructure (Eloquent/PostgreSQL).
 *
 * El dominio y la aplicación hablan solo con esta interfaz: así el patrón outbox no depende de
 * Eloquent ni del motor de base.
 */
interface OutboxStore
{
    /**
     * Agrega un mensaje al outbox. Idempotente por eventId: si ya existe un mensaje con ese eventId,
     * no lo duplica (la unicidad la garantiza un índice único sobre event_id).
     *
     * Debe correr dentro de la misma transacción que el cambio de negocio que generó el evento.
     */
    public function append(OutboxMessage $message): void;

    /**
     * Toma un lote de mensajes despachables (Pending/Failed con availableAt <= now), bloqueándolos
     * para que varios workers no tomen el mismo mensaje (FOR UPDATE SKIP LOCKED en PostgreSQL).
     *
     * @return list<OutboxMessage>
     */
    public function pullBatch(int $limit, DateTimeImmutable $now): array;

    /**
     * Persiste el estado actual de un mensaje ya existente (tras despacharlo, fallar o descartarlo).
     */
    public function save(OutboxMessage $message): void;
}
