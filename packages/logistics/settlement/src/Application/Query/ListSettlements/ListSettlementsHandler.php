<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Query\ListSettlements;

use Logistics\Settlement\Application\ReadModel\SettlementView;
use Logistics\Settlement\Domain\Contract\SellerDirectory;
use Logistics\Settlement\Domain\Contract\SellerSettlementRepository;
use Logistics\Settlement\Domain\Model\SellerSettlement;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las liquidaciones (filtrables por seller, estado y periodo) como proyecciones de lectura,
 * enriquecidas con el nombre legible del seller (resuelto EN LOTE para evitar N+1).
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa, por compatibilidad.
 *
 * @implements QueryHandler<ListSettlements, list<SettlementView>|Page<SettlementView>>
 */
final readonly class ListSettlementsHandler implements QueryHandler
{
    public function __construct(
        private SellerSettlementRepository $settlements,
        private SellerDirectory $sellers,
    ) {}

    /**
     * @return list<SettlementView>|Page<SettlementView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListSettlements);

        $settlements = $this->settlements->list(
            $query->sellerId,
            $query->status,
            $query->periodFrom,
            $query->periodTo,
            $query->limit,
            $query->offset,
        );

        $sellerIds = array_values(array_unique(array_map(
            static fn (SellerSettlement $settlement): string => $settlement->sellerId(),
            $settlements,
        )));
        $sellerNames = $sellerIds === [] ? [] : $this->sellers->namesByIds($sellerIds);

        $views = array_map(
            static fn (SellerSettlement $settlement): SettlementView => SettlementView::fromDomain(
                $settlement,
                $sellerNames[$settlement->sellerId()] ?? null,
            ),
            $settlements,
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->settlements->countBy(
            $query->sellerId,
            $query->status,
            $query->periodFrom,
            $query->periodTo,
        ));
    }
}
