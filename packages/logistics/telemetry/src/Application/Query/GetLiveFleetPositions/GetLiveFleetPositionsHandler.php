<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetLiveFleetPositions;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;

/**
 * Devuelve la posición en vivo de toda la flota como proyecciones de lectura.
 *
 * @implements QueryHandler<GetLiveFleetPositions, list<VehicleLiveView>>
 */
final readonly class GetLiveFleetPositionsHandler implements QueryHandler
{
    public function __construct(
        private VehiclePositionRepository $positions,
    ) {}

    /**
     * @return list<VehicleLiveView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetLiveFleetPositions);

        return array_map(
            static fn (array $row): VehicleLiveView => VehicleLiveView::fromRow($row),
            $this->positions->all(),
        );
    }
}
