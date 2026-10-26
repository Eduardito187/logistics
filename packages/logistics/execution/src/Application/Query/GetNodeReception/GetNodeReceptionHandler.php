<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetNodeReception;

use Logistics\Execution\Application\Contract\ShipmentUnitProductReader;
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
        private readonly ShipmentUnitProductReader $unitProducts,
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

        if ($reception === null) {
            return null;
        }

        // Producto de cada bulto (esperados ∪ recibidos ∪ sobrantes) en una sola lectura en lote.
        $unitIds = array_values(array_unique([
            ...$reception->expectedUnitIds(),
            ...$reception->receivedUnitIds(),
            ...$reception->surplusUnitIds(),
        ]));

        return NodeReceptionView::fromNodeReception($reception, $this->unitProducts->forUnitIds($unitIds));
    }
}
