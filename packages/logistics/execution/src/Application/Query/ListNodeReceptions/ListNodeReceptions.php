<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListNodeReceptions;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista paginada (LIMIT/OFFSET en SQL) de recepciones en nodo, filtrable por nodo destino y estado, con el
 * resumen de cada una (conteos) y la referencia legible del envío. Para la lista de la tienda sin N+1.
 */
final readonly class ListNodeReceptions implements Query
{
    public function __construct(
        public ?string $nodeId,
        public ?string $status,
        public int $limit,
        public int $offset,
    ) {}
}
