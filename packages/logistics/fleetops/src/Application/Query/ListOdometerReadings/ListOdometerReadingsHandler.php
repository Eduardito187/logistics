<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListOdometerReadings;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\OdometerView;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las lecturas de odómetro que cumplen los filtros como proyecciones de lectura.
 *
 * @implements QueryHandler<ListOdometerReadings, list<OdometerView>>
 */
final readonly class ListOdometerReadingsHandler implements QueryHandler
{
    public function __construct(
        private OdometerRepository $readings,
    ) {}

    /**
     * @return list<OdometerView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListOdometerReadings);

        $readings = $this->readings->listBy(
            $query->vehicleId,
            $query->from !== null ? new DateTimeImmutable($query->from) : null,
            $query->to !== null ? new DateTimeImmutable($query->to) : null,
        );

        return array_map(
            static fn (OdometerReading $reading): OdometerView => OdometerView::fromDomain($reading),
            $readings,
        );
    }
}
