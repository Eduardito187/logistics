<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\GetStockOnHand;

use Logistics\Inventory\Application\ReadModel\StockOnHandView;
use Logistics\Inventory\Domain\Contract\StockQueryStore;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el stock on-hand de un almacén/sku agregado por dueño (una fila por dueño).
 *
 * @implements QueryHandler<GetStockOnHand, list<StockOnHandView>>
 */
final readonly class GetStockOnHandHandler implements QueryHandler
{
    public function __construct(
        private StockQueryStore $store,
    ) {}

    /**
     * @return list<StockOnHandView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetStockOnHand);

        $rows = $this->store->onHandByOwner(
            $query->warehouseId,
            $query->sku,
            $query->ownerPartyId,
            $query->fulfillmentMode,
        );

        return array_map(static fn (array $row): StockOnHandView => StockOnHandView::fromRow($row), $rows);
    }
}
