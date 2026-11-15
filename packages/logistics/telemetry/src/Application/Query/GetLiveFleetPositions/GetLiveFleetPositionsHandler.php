<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetLiveFleetPositions;

use DateTimeImmutable;
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
 * Respeta el alcance de quien consulta (`FleetLiveScope`: todas las ciudades, una, o ninguna) y deja fuera
 * los vehículos dados de baja en flota; ambos filtros se resuelven en la consulta de posiciones.
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

        $rows = $this->visibleRows($query);

        if (!$query->enrich || $rows === []) {
            return array_map(static fn (array $row): VehicleLiveView => VehicleLiveView::fromRow($row), $rows);
        }

        $vehicleIds = array_map(static fn (array $row): string => $row['vehicle_id'], $rows);
        $plates = $this->fleet->plates($vehicleIds);
        $bases = $this->fleet->homeBases($vehicleIds);

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
            static function (array $row) use ($plates, $driverIds, $names, $bases): VehicleLiveView {
                $driverId = $driverIds[$row['vehicle_id']] ?? null;

                return VehicleLiveView::fromRow(
                    $row,
                    $plates[$row['vehicle_id']] ?? null,
                    $driverId,
                    $driverId !== null ? ($names[$driverId] ?? null) : null,
                    $bases[$row['vehicle_id']] ?? null,
                );
            },
            $rows,
        );
    }

    /**
     * Filas de la flota que entran en el alcance de la consulta. El filtro viaja a la CONSULTA de posiciones
     * (ids permitidos / excluidos), no se aplica después en memoria:
     *  - alcance por ciudad: solo los vehículos con sede en ese departamento;
     *  - siempre: fuera los vehículos dados de baja en flota.
     *
     * @return list<array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable, driver_id?: string|null, updated_at?: DateTimeImmutable|null}>
     */
    private function visibleRows(GetLiveFleetPositions $query): array
    {
        $scope = $query->scope;
        if ($scope->seesNothing()) {
            return [];
        }

        $retired = $this->fleet->retiredVehicleIds();

        $city = $scope->city();
        if ($city === null) {
            return $this->positions->visible($query->since, null, $retired);
        }

        $only = array_values(array_diff($this->fleet->vehicleIdsInDepartment($city), $retired));

        return $this->positions->visible($query->since, $only);
    }
}
