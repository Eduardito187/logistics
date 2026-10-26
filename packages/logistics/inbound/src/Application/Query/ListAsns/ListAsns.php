<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\ListAsns;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista ASNs filtrando por seller, almacén, estado y/o fecha de cita (cualquiera nullable = sin filtro).
 */
final readonly class ListAsns implements Query
{
    public function __construct(
        public ?string $sellerId = null,
        public ?string $warehouseId = null,
        public ?string $status = null,
        public ?string $appointmentDate = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
