<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\ListReceivingDocks;

use Logistics\Network\Application\ReadModel\ReceivingDockView;
use Logistics\Network\Domain\Contract\ReceivingDockRepository;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los andenes de recepción de un almacén como modelos de lectura, ordenados por código.
 *
 * @implements QueryHandler<ListReceivingDocksQuery, list<ReceivingDockView>>
 */
final readonly class ListReceivingDocksHandler implements QueryHandler
{
    public function __construct(private ReceivingDockRepository $docks) {}

    /**
     * @return list<ReceivingDockView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListReceivingDocksQuery);

        $docks = $this->docks->byWarehouse(NodeId::fromString($query->warehouseId));

        return array_map(ReceivingDockView::fromDock(...), $docks);
    }
}
