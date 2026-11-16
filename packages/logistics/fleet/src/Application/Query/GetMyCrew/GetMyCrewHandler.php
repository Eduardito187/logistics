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
 * Resuelve la asignación VIGENTE de un TRIPULANTE (chofer o ayudante) en una fecha y la devuelve como
 * proyección de lectura. El chofer se resuelve por su `driverId`; el ayudante, por su `helperId` (figura en
 * los `helper_ids` de la tripulación). En ambos casos la vista es la misma.
 *
 * Un chofer tiene UN camión por día (una sola tripulación vigente; cambiarle el camión reemplaza la
 * anterior), y con ese camión puede salir varias veces. Se devuelve su tripulación con la ruta de su
 * SALIDA VIGENTE:
 *
 *   1. la ruta abierta de su camión (en curso antes que planificada);
 *   2. si no hay ninguna ruta abierta, la última ruta de ese camión en la fecha, si existe.
 *
 * Tolerancia a datos viejos: si quedara más de una tripulación del chofer en el día (antes del saneo
 * `logistics:fleet:reconcile-crews`), manda la de ruta abierta y, sin rutas, la armada más tarde; nunca
 * la primera que aparezca.
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

        $rows = $this->mostRecentFirst($this->viewerCrews($query));
        if ($rows === []) {
            return null;
        }

        $routes = $this->routesOfViewer($query->date, $rows);
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
     * Tripulaciones del día que ve el solicitante: las del CHOFER (filtro por `driver_id`) o, si la cuenta
     * es un AYUDANTE, aquellas en las que figura (filtro por `helper_ids`). Devuelve [] si no trae ningún
     * vínculo utilizable.
     *
     * @return list<CrewRow>
     */
    private function viewerCrews(GetMyCrew $query): array
    {
        if ($query->driverId !== null && $query->driverId !== '') {
            return $this->directory->list($query->date, null, $query->driverId, self::MAX_CREWS_PER_DAY, 0)['items'];
        }

        if ($query->helperId !== null && $query->helperId !== '') {
            return $this->directory->listForHelper($query->date, $query->helperId, self::MAX_CREWS_PER_DAY, 0)['items'];
        }

        return [];
    }

    /**
     * Rutas del día de los camiones de las tripulaciones del solicitante. Si la ruta tiene chofer grabado y
     * no es el de NINGUNA de esas tripulaciones, no es su salida (dos choferes pueden compartir el camión en
     * el día); sin chofer grabado vale el vínculo por camión. Vale igual para el chofer (un solo driver_id
     * en sus filas) y para el ayudante (el driver_id del chofer de su tripulación).
     *
     * @param  list<CrewRow>        $rows
     * @return list<DepartureRoute>
     */
    private function routesOfViewer(string $date, array $rows): array
    {
        $vehicleIds = array_values(array_unique(array_column($rows, 'vehicle_id')));
        $driverIds = array_values(array_unique(array_column($rows, 'driver_id')));

        return array_values(array_filter(
            $this->routes->onDate($date, $vehicleIds),
            static fn (array $route): bool => $route['driver_id'] === null || in_array($route['driver_id'], $driverIds, true),
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
     * tienen (las que no fijaron turno van después) y, a igualdad, conserva el orden del directorio (la
     * armada más tarde primero).
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
