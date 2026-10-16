<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\ListLots;

use Logistics\Inventory\Application\ReadModel\LotView;
use Logistics\Inventory\Domain\Contract\StockQueryStore;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve lotes como proyecciones de lectura, con filtros opcionales.
 *
 * @implements QueryHandler<ListLots, list<LotView>>
 */
final readonly class ListLotsHandler implements QueryHandler
{
    public function __construct(
        private StockQueryStore $store,
    ) {}

    /**
     * @return list<LotView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListLots);

        $rows = $this->store->lots(
            $query->warehouseId,
            $query->sku,
            $query->ownerPartyId,
            $query->fulfillmentMode,
            $query->limit,
            $query->offset,
        );

        return array_map(static fn (array $row): LotView => LotView::fromRow($row), $rows);
    }
}
