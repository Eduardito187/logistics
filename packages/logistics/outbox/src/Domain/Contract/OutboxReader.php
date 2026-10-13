<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain\Contract;

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;

/**
 * Puerto de LECTURA del outbox (lado query de CQRS). Separado de OutboxStore (lado escritura) para
 * que el panel y la API puedan consultar sin arrastrar la semántica del relay (lease, SKIP LOCKED).
 *
 * La implementación vive en Infrastructure (Eloquent/PostgreSQL). El dominio y la aplicación hablan
 * solo con esta interfaz.
 */
interface OutboxReader
{
    /**
     * Devuelve un mensaje por su id, o null si no existe. Lectura simple (sin bloqueo).
     */
    public function findById(OutboxMessageId $id): ?OutboxMessage;

    /**
     * Lista mensajes (opcionalmente filtrados por estado) ordenados del más reciente al más antiguo,
     * paginando por offset/limit. Devuelve entidades de dominio; el handler las proyecta a ReadModel.
     *
     * @return list<OutboxMessage>
     */
    public function list(?OutboxStatus $status, int $limit, int $offset): array;

    /**
     * Cuenta cuántos mensajes hay (opcionalmente filtrados por estado). Sirve para la paginación.
     */
    public function count(?OutboxStatus $status): int;

    /**
     * Conteo por estado, con TODOS los estados presentes (los vacíos en 0). Clave = valor del enum.
     *
     * @return array<string, int>
     */
    public function countByStatus(): array;
}
