<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\ListAsns;

use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Lista ASNs con los filtros dados, como ReadModels.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista (tope sano del repositorio), para el tablero Filament que pide por estado.
 *
 * @implements QueryHandler<ListAsns, list<AsnView>|Page<AsnView>>
 */
final readonly class ListAsnsHandler implements QueryHandler
{
    public function __construct(
        private AsnRepository $asns,
    ) {}

    /**
     * @return list<AsnView>|Page<AsnView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListAsns);

        $views = array_map(
            static fn (Asn $asn): AsnView => AsnView::fromDomain($asn),
            $this->asns->listBy(
                $query->sellerId,
                $query->warehouseId,
                $query->status,
                $query->appointmentDate,
                $query->limit,
                $query->offset,
            ),
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->asns->countBy(
            $query->sellerId,
            $query->warehouseId,
            $query->status,
            $query->appointmentDate,
        ));
    }
}
