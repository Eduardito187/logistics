<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Query\GetSeller;

use Logistics\Sellers\Application\ReadModel\SellerView;
use Logistics\Sellers\Domain\Contract\SellerRepository;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve un seller por su id, o null si no existe.
 *
 * @implements QueryHandler<GetSeller, SellerView|null>
 */
final readonly class GetSellerHandler implements QueryHandler
{
    public function __construct(
        private SellerRepository $sellers,
    ) {}

    public function handle(Query $query): ?SellerView
    {
        assert($query instanceof GetSeller);

        $seller = $this->sellers->findById(SellerId::fromString($query->sellerId));

        return $seller !== null ? SellerView::fromDomain($seller) : null;
    }
}
