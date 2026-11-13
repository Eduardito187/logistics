<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListNodeReceptions;

use Logistics\Execution\Application\Contract\NodeReceptionListReader;
use Logistics\Execution\Application\ReadModel\NodeReceptionSummaryView;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<ListNodeReceptions, array{items: list<NodeReceptionSummaryView>, total: int}>
 */
final class ListNodeReceptionsHandler implements QueryHandler
{
    public function __construct(
        private readonly NodeReceptionListReader $reader,
    ) {}

    /**
     * @return array{items: list<NodeReceptionSummaryView>, total: int}
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListNodeReceptions) {
            throw ExecutionException::because('ListNodeReceptionsHandler solo maneja ListNodeReceptions');
        }

        // El estado llega en minúsculas desde la app ('open'); se normaliza al valor del dominio.
        $status = null;
        if ($query->status !== null && trim($query->status) !== '') {
            $status = (NodeReceptionStatus::tryFromLoose($query->status)
                ?? throw ExecutionException::because("estado de recepción desconocido: {$query->status}"))->value;
        }

        $nodeId = $query->nodeId !== null && trim($query->nodeId) !== '' ? trim($query->nodeId) : null;

        return $this->reader->page($nodeId, $status, max(1, $query->limit), max(0, $query->offset));
    }
}
