<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListCarriers;

use Logistics\Carriers\Application\ReadModel\CarrierView;
use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve la lista de transportistas como proyecciones de lectura.
 *
 * @implements QueryHandler<ListCarriers, list<CarrierView>>
 */
final readonly class ListCarriersHandler implements QueryHandler
{
    public function __construct(
        private CarrierRepository $carriers,
    ) {}

    /**
     * @return list<CarrierView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListCarriers);

        $carriers = $this->carriers->all();
        if ($query->status !== null) {
            $carriers = array_values(array_filter(
                $carriers,
                static fn (Carrier $carrier): bool => $carrier->status()->value === $query->status,
            ));
        }

        return array_map(static fn (Carrier $carrier): CarrierView => CarrierView::fromDomain($carrier), $carriers);
    }
}
