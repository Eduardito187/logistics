<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\GetMyCrew;

use Logistics\Fleet\Application\Contract\CrewDirectory;
use Logistics\Fleet\Application\Contract\DepartureRouteReader;
use Logistics\Fleet\Application\ReadModel\MyCrewView;
use Logistics\Fleet\Domain\Contract\CrewRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve la asignación VIGENTE de un chofer en una fecha y la devuelve como proyección de lectura.
 *
 * El chofer puede salir varias veces el mismo día, con el mismo camión o con otro: tiene una tripulación
 * por cada camión que le asignaron. La que se devuelve es la de su SALIDA VIGENTE:
 *
 *   1. la tripulación del camión cuya ruta está abierta (en curso antes que planificada);
 *   2. si no hay ninguna ruta abierta, la tripulación más reciente del día (con la última ruta de ese
 *      camión en la fecha, si existe).
 *
 * Sin tripulación en la fecha devuelve null (el controller lo traduce a 404). El vehículo, el chofer y
 * cada ayudante se enriquecen con sus datos legibles (placa, nombre) contra sus repositorios.
 *
 * @phpstan-import-type CrewRow from CrewDirectory
 * @phpstan-import-type DepartureRoute from DepartureRouteReader
 *
 * @implements QueryHandler<GetMyCrew, MyCrewView|null>
 */
final readonly class GetMyCrewHandler implements QueryHandler
{
    /** Tope de tripulaciones de un chofer en un día que se miran (muy por encima de lo operativo). */
    private const int MAX_CREWS_PER_DAY = 50;

    /** Estados de una ruta abierta, en orden de prioridad: la que ya salió manda sobre la planificada. */
    private const array OPEN_STATUSES = ['IN_PROGRESS', 'PLANNED'];

    public function __construct(
        private CrewRepository $crews,
        private VehicleRepository $vehicles,
        private DriverRepository $drivers,
        private HelperRepository $helpers,
        private CrewDirectory $directory,
        private DepartureRouteReader $routes,
    ) {}

    public function handle(Query $query): ?MyCrewView
    {
        assert($query instanceof GetMyCrew);

        $rows = $this->mostRecentFirst(
            $this->directory->list($query->date, null, $query->driverId, self::MAX_CREWS_PER_DAY, 0)['items'],
        );
        if ($rows === []) {
            return null;
        }

        $routes = $this->routesOfDriver($query, $rows);
        [$row, $route] = $this->currentDeparture($rows, $routes);

        $crew = $this->crews->findById(CrewId::fromString($row['id']));
        if ($crew === null) {
            return null;
        }

        $vehicle = $this->vehicles->findById($crew->vehicleId());
        $driver = $this->drivers->findById($crew->driverId());

        // Todos los ayudantes de la tripulación en una sola consulta (antes una por ayudante).
        $helpersById = $this->helpers->findByIds(array_values($crew->helperIds()));

        return MyCrewView::fromDomain(
            $crew,
            $vehicle,
            $driver,
            $helpersById,
            $route['route_id'] ?? null,
            $route['status'] ?? null,
        );
    }

    /**
     * Rutas del día de los camiones del chofer. Si la ruta tiene chofer grabado y es OTRO, no es su salida
     * (dos choferes pueden compartir el camión en el día); sin chofer grabado vale el vínculo por camión.
     *
     * @param  list<CrewRow>        $rows
     * @return list<DepartureRoute>
     */
    private function routesOfDriver(GetMyCrew $query, array $rows): array
    {
        $vehicleIds = array_values(array_unique(array_column($rows, 'vehicle_id')));

        return array_values(array_filter(
            $this->routes->onDate($query->date, $vehicleIds),
            static fn (array $route): bool => $route['driver_id'] === null || $route['driver_id'] === $query->driverId,
        ));
    }

    /**
     * Elige la tripulación de la salida vigente y su ruta (null si el camión todavía no tiene ruta).
     *
     * @param  non-empty-list<CrewRow>                   $rows   más reciente primero
     * @param  list<DepartureRoute>                      $routes más reciente primero
     * @return array{0: CrewRow, 1: DepartureRoute|null}
     */
    private function currentDeparture(array $rows, array $routes): array
    {
        foreach (self::OPEN_STATUSES as $status) {
            foreach ($routes as $route) {
                if ($route['status'] !== $status) {
                    continue;
                }
                foreach ($rows as $row) {
                    if ($row['vehicle_id'] === $route['vehicle_id']) {
                        return [$row, $route];
                    }
                }
            }
        }

        // Sin ruta abierta: la tripulación más reciente del día, con la última ruta de su camión.
        $latest = $rows[0];
        foreach ($routes as $route) {
            if ($route['vehicle_id'] === $latest['vehicle_id']) {
                return [$latest, $route];
            }
        }

        return [$latest, null];
    }

    /**
     * Ordena las tripulaciones del día de la más reciente a la más vieja: por inicio de turno cuando lo
     * tienen (las que no fijaron turno van después) y, a igualdad, conserva el orden del directorio (id
     * UUID v7 descendente = la armada más tarde primero).
     *
     * @param  list<CrewRow> $rows
     * @return list<CrewRow>
     */
    private function mostRecentFirst(array $rows): array
    {
        $position = array_flip(array_column($rows, 'id'));

        usort($rows, static function (array $a, array $b) use ($position): int {
            $byShift = ($b['shift_starts_at'] ?? '') <=> ($a['shift_starts_at'] ?? '');

            return $byShift !== 0 ? $byShift : $position[$a['id']] <=> $position[$b['id']];
        });

        return $rows;
    }
}
