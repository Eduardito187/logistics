<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\GetSettlement;

use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Domain\Contract\SellerDirectory;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve una liquidación por su id, o null si no existe. Enriquece con el nombre legible del seller.
 *
 * @implements QueryHandler<GetSettlement, SettlementView|null>
 */
final readonly class GetSettlementHandler implements QueryHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private SellerDirectory $sellers,
    ) {}

    public function handle(Query $query): ?SettlementView
    {
        assert($query instanceof GetSettlement);

        $settlement = $this->settlements->findById(SettlementId::fromString($query->settlementId));

        if ($settlement === null) {
            return null;
        }

        return SettlementView::fromDomain($settlement, $this->sellers->nameById($settlement->sellerId()));
    }
}
