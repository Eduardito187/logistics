<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\Query\GetAvailableToPromise;

use Logistics\Inventory\Application\ReadModel\AvailableToPromiseView;
use Logistics\Inventory\Domain\Contract\StockQueryStore;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el Available-to-Promise de un almacén/sku (opcionalmente por dueño) para el motor de promesa.
 *
 * @implements QueryHandler<GetAvailableToPromise, AvailableToPromiseView>
 */
final readonly class GetAvailableToPromiseHandler implements QueryHandler
{
    public function __construct(
        private StockQueryStore $store,
    ) {}

    public function handle(Query $query): AvailableToPromiseView
    {
        assert($query instanceof GetAvailableToPromise);

        $atp = $this->store->availableToPromise($query->warehouseId, $query->sku, $query->ownerPartyId);

        return new AvailableToPromiseView(
            $query->warehouseId,
            $query->sku,
            $query->ownerPartyId,
            $atp,
        );
    }
}
