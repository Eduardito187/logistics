<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetNodeReception;

use Logistics\Execution\Application\ReadModel\NodeReceptionView;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetNodeReception, ?NodeReceptionView>
 */
final class GetNodeReceptionHandler implements QueryHandler
{
    public function __construct(
        private readonly NodeReceptionRepository $receptions,
    ) {}

    public function handle(Query $query): ?NodeReceptionView
    {
        if (!$query instanceof GetNodeReception) {
            throw ExecutionException::because('GetNodeReceptionHandler solo maneja GetNodeReception');
        }

        $reception = $this->receptions->findByShipmentAndDestination(
            $query->shipmentId,
            $query->destinationNodeId,
        );

        return $reception === null ? null : NodeReceptionView::fromNodeReception($reception);
    }
}
