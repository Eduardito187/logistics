<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lista todas las zonas como modelos de lectura.
 *
 * @implements QueryHandler<ListZones, array<int, ZoneView>>
 */
final readonly class ListZonesHandler implements QueryHandler
{
    public function __construct(private ZoneRepository $zones) {}

    /**
     * @return array<int, ZoneView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListZones);

        return array_map(
            static fn (Zone $zone): ZoneView => ZoneView::fromZone($zone),
            $this->zones->all(),
        );
    }
}
