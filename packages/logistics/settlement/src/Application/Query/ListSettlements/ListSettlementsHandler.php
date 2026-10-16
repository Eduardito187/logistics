<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\ListSettlements;

use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Domain\Contract\SellerDirectory;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las liquidaciones (filtrables por seller, estado y periodo) como proyecciones de lectura,
 * enriquecidas con el nombre legible del seller (resuelto EN LOTE para evitar N+1).
 *
 * @implements QueryHandler<ListSettlements, list<SettlementView>>
 */
final readonly class ListSettlementsHandler implements QueryHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private SellerDirectory $sellers,
    ) {}

    /**
     * @return list<SettlementView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListSettlements);

        $settlements = $this->settlements->list(
            $query->sellerId,
            $query->status,
            $query->periodFrom,
            $query->periodTo,
        );

        $sellerIds = array_values(array_unique(array_map(
            static fn (SellerSettlement $settlement): string => $settlement->sellerId(),
            $settlements,
        )));
        $sellerNames = $sellerIds === [] ? [] : $this->sellers->namesByIds($sellerIds);

        return array_map(
            static fn (SellerSettlement $settlement): SettlementView => SettlementView::fromDomain(
                $settlement,
                $sellerNames[$settlement->sellerId()] ?? null,
            ),
            $settlements,
        );
    }
}
