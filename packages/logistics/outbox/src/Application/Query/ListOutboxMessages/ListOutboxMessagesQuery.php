<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Query\ListOutboxMessages;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista mensajes del outbox, opcionalmente filtrados por estado, con paginación por página/tamaño.
 * $status es el valor del enum OutboxStatus (o null para todos).
 */
final readonly class ListOutboxMessagesQuery implements Query
{
    public function __construct(
        public ?string $status,
        public int $page,
        public int $perPage,
    ) {}
}
