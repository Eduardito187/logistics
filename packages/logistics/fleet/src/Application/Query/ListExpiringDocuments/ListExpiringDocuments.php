<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListExpiringDocuments;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los documentos (de vehículos y choferes) que vencen dentro de N días, incluidos los vencidos.
 *
 * `referenceDate` (Y-m-d) permite consultar a una fecha dada; si es null, el handler usa hoy.
 */
final readonly class ListExpiringDocuments implements Query
{
    public function __construct(
        public int $days,
        public ?string $referenceDate = null,
    ) {}
}
