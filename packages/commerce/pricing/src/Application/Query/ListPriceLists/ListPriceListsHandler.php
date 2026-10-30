<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\Query\ListPriceLists;

use Commerce\Pricing\Application\ReadModel\PriceListView;
use Commerce\Pricing\Domain\Contract\PriceListRepository;
use Commerce\Pricing\Domain\Model\PriceList;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las listas de precios como proyecciones de lectura, con filtro opcional por canal.
 *
 * @implements QueryHandler<ListPriceLists, list<PriceListView>>
 */
final readonly class ListPriceListsHandler implements QueryHandler
{
    public function __construct(
        private PriceListRepository $priceLists,
    ) {}

    /**
     * @return list<PriceListView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPriceLists);

        $lists = $this->priceLists->all();
        if ($query->channel !== null) {
            $lists = array_values(array_filter(
                $lists,
                static fn (PriceList $list): bool => $list->channel()->value === $query->channel,
            ));
        }

        return array_map(static fn (PriceList $list): PriceListView => PriceListView::fromDomain($list), $lists);
    }
}
