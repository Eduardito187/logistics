<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\ListAsns;

use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista ASNs con los filtros dados, como ReadModels.
 *
 * @implements QueryHandler<ListAsns, list<AsnView>>
 */
final readonly class ListAsnsHandler implements QueryHandler
{
    public function __construct(
        private AsnRepository $asns,
    ) {}

    /**
     * @return list<AsnView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListAsns);

        $asns = $this->asns->listBy(
            $query->sellerId,
            $query->warehouseId,
            $query->status,
            $query->appointmentDate,
        );

        return array_map(static fn (Asn $asn): AsnView => AsnView::fromDomain($asn), $asns);
    }
}
