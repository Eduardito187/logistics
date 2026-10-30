<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Query\ListPrices;

use Commerce\Pricing\Application\ReadModel\PriceView;
use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\PriceListId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los precios de una lista como proyecciones de lectura.
 *
 * @implements QueryHandler<ListPrices, list<PriceView>>
 */
final readonly class ListPricesHandler implements QueryHandler
{
    public function __construct(
        private PriceListRepository $priceLists,
    ) {}

    /**
     * @return list<PriceView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPrices);

        $listId = PriceListId::fromString($query->priceListId);

        return array_map(
            /**
             * @param array{sku: string, gross_amount: Money} $row
             */
            static fn (array $row): PriceView => PriceView::fromPrimitives($query->priceListId, $row['sku'], $row['gross_amount']),
            $this->priceLists->listPrices($listId),
        );
    }
}
