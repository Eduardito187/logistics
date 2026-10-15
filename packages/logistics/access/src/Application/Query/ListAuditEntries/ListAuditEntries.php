<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\ListAuditEntries;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista las entradas de auditoría más recientes. Opcionalmente filtra por acción y/o tipo de sujeto, y
 * acota el número máximo de filas devueltas.
 */
final readonly class ListAuditEntries implements Query
{
    public function __construct(
        public int $limit = 100,
        public ?string $action = null,
        public ?string $subjectType = null,
    ) {}
}
