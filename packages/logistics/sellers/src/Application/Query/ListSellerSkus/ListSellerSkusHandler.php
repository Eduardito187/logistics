<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Query\ListSellerSkus;

use Logistics\Sellers\Application\ReadModel\SellerSkuView;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Domain\ValueObject\SellerSku;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve el catálogo de SKUs de un seller como proyecciones de lectura.
 *
 * @implements QueryHandler<ListSellerSkus, list<SellerSkuView>>
 */
final readonly class ListSellerSkusHandler implements QueryHandler
{
    public function __construct(
        private SellerRepository $sellers,
    ) {}

    /**
     * @return list<SellerSkuView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListSellerSkus);

        $sellerId = SellerId::fromString($query->sellerId);

        return array_map(
            static fn (SellerSku $sku): SellerSkuView => SellerSkuView::fromDomain($query->sellerId, $sku),
            $this->sellers->listSkus($sellerId),
        );
    }
}
