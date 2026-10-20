<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\ListLots;

use Logistics\Inventory\Application\ReadModel\LotView;
use Logistics\Inventory\Domain\Contract\StockQueryStore;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve una página de lotes como proyecciones de lectura, con filtros opcionales.
 *
 * Tabla de crecimiento ilimitado: siempre pagina. Baja LIMIT/OFFSET a SQL y arma el total con un
 * COUNT(*) aparte (sin recorrer la tabla). Devuelve una Page (ítems + total).
 *
 * @implements QueryHandler<ListLots, Page<LotView>>
 */
final readonly class ListLotsHandler implements QueryHandler
{
    public function __construct(
        private StockQueryStore $store,
    ) {}

    /**
     * @return Page<LotView>
     */
    public function handle(Query $query): Page
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

        $views = array_map(static fn (array $row): LotView => LotView::fromRow($row), $rows);

        return new Page($views, $this->store->countLots(
            $query->warehouseId,
            $query->sku,
            $query->ownerPartyId,
            $query->fulfillmentMode,
        ));
    }
}
