<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

use Logistics\Execution\Application\ReadModel\NodeReceptionSummaryView;

/**
 * Lado lectura (CQRS) del listado de recepciones en nodo para la app de tienda: una página de resúmenes
 * (estado + conteos) con la referencia legible del envío (pedido, cliente), resuelta EN LOTE y paginada en
 * SQL. Nunca carga agregados ni hace una consulta por fila.
 */
interface NodeReceptionListReader
{
    /**
     * @param  string|null                                              $nodeId nodo destino (null = todos)
     * @param  string|null                                              $status 'OPEN' | 'RECONCILED' (null = todos)
     * @return array{items: list<NodeReceptionSummaryView>, total: int}
     */
    public function page(?string $nodeId, ?string $status, int $limit, int $offset): array;
}
