<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Query\ListSellers;

use Logistics\Sellers\Application\ReadModel\SellerView;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\Model\Seller;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de sellers como proyecciones de lectura, con filtro opcional por estado.
 *
 * @implements QueryHandler<ListSellers, list<SellerView>>
 */
final readonly class ListSellersHandler implements QueryHandler
{
    public function __construct(
        private SellerRepository $sellers,
    ) {}

    /**
     * @return list<SellerView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListSellers);

        $sellers = $this->sellers->all();
        if ($query->status !== null) {
            $sellers = array_values(array_filter(
                $sellers,
                static fn (Seller $seller): bool => $seller->status()->value === $query->status,
            ));
        }

        return array_map(static fn (Seller $seller): SellerView => SellerView::fromDomain($seller), $sellers);
    }
}
