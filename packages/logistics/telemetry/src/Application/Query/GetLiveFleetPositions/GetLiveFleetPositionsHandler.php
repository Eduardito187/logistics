<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetLiveFleetPositions;

use DateTimeZone;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;

/**
 * Devuelve la posición en vivo de la flota como proyecciones de lectura, enriquecidas con placa y chofer.
 *
 * Enriquecimiento EN LOTE (sin N+1): una query de placas, una de choferes asignados a la ruta del día (solo
 * para los vehículos cuya última lectura no trae chofer) y una de nombres. Con `since`, solo las posiciones
 * que cambiaron después de ese instante.
 *
 * @implements QueryHandler<GetLiveFleetPositions, list<VehicleLiveView>>
 */
final readonly class GetLiveFleetPositionsHandler implements QueryHandler
{
    public function __construct(
        private VehiclePositionRepository $positions,
        private FleetDirectory $fleet,
        private Clock $clock,
    ) {}

    /**
     * @return list<VehicleLiveView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetLiveFleetPositions);

        $rows = $query->since !== null
            ? $this->positions->changedSince($query->since)
            : $this->positions->all();

        if (!$query->enrich || $rows === []) {
            return array_map(static fn (array $row): VehicleLiveView => VehicleLiveView::fromRow($row), $rows);
        }

        $vehicleIds = array_map(static fn (array $row): string => $row['vehicle_id'], $rows);
        $plates = $this->fleet->plates($vehicleIds);

        // Chofer: el de la última lectura; si no vino, el asignado a la ruta del día del vehículo.
        $withoutDriver = array_values(array_filter(
            $vehicleIds,
            static fn (string $id, int $i): bool => ($rows[$i]['driver_id'] ?? null) === null,
            ARRAY_FILTER_USE_BOTH,
        ));
        $today = $this->clock->now()
            ->setTimezone(new DateTimeZone((string) config('logistics-telemetry.working_hours.timezone', 'America/La_Paz')))
            ->setTime(0, 0);
        $assigned = $withoutDriver !== [] ? $this->fleet->assignedDriverIds($withoutDriver, $today) : [];

        $driverIds = [];
        foreach ($rows as $row) {
            $driverIds[$row['vehicle_id']] = ($row['driver_id'] ?? null) ?? ($assigned[$row['vehicle_id']] ?? null);
        }
        $names = $this->fleet->driverNames(array_values(array_unique(array_filter($driverIds, static fn (?string $id): bool => $id !== null))));

        return array_map(
            static function (array $row) use ($plates, $driverIds, $names): VehicleLiveView {
                $driverId = $driverIds[$row['vehicle_id']] ?? null;

                return VehicleLiveView::fromRow(
                    $row,
                    $plates[$row['vehicle_id']] ?? null,
                    $driverId,
                    $driverId !== null ? ($names[$driverId] ?? null) : null,
                );
            },
            $rows,
        );
    }
}
