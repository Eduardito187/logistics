<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\ListPendingArrivals;

use Logistics\Inbound\Application\ReadModel\AsnView;
use Logistics\Inbound\Domain\Contract\AsnRepository;
use Logistics\Inbound\Domain\Model\Asn;
use Logistics\Inbound\Domain\ValueObject\AsnStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista los ASNs agendados pendientes de llegada (Scheduled), como ReadModels.
 *
 * @implements QueryHandler<ListPendingArrivals, list<AsnView>>
 */
final readonly class ListPendingArrivalsHandler implements QueryHandler
{
    public function __construct(
        private AsnRepository $asns,
    ) {}

    /**
     * @return list<AsnView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListPendingArrivals);

        $asns = $this->asns->listBy(null, $query->warehouseId, AsnStatus::Scheduled->value, null);

        return array_map(static fn (Asn $asn): AsnView => AsnView::fromDomain($asn), $asns);
    }
}
