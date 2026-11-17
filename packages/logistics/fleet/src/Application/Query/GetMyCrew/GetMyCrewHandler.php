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
 * Asignación VIGENTE y MEDIANOCHE: cuando se pide la asignación actual (`allowOpenCarryover`), si no hay
 * tripulación en la fecha pero el chofer tiene una ruta EN CURSO de otro día (una entrega que cruzó la
 * medianoche o larga a otra ciudad/país), se devuelve la tripulación de ESA ruta abierta hasta que se cierre:
 * la asignación la rige el ESTADO de la ruta, no el calendario. Nunca se le quita una entrega en curso.
 *
 * Sin tripulación en la fecha (ni ruta abierta que arrastre) devuelve null (el controller lo traduce a 404).
 * El vehículo, el chofer y cada ayudante se enriquecen con sus datos legibles (placa, nombre).
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

    /**
     * Estados de una ruta que SIGUE siendo la asignación vigente del chofer aunque haya cambiado el día: una
     * entrega EN CURSO (o pausada) no se le quita al cruzar la medianoche. Una ruta sólo PLANIFICADA de un día
     * pasado NO arrastra (nunca arrancó): es stock viejo, no una entrega en curso.
     */
    private const array CARRYOVER_STATUSES = ['IN_PROGRESS', 'SUSPENDED'];

    /** Tripulaciones recientes del chofer (varios días) que se miran para encontrar su ruta abierta. */
    private const int RECENT_CREWS = 10;

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

        $date = $query->date;
        $rows = $this->mostRecentFirst($this->viewerCrews($query, $date));

        // Entrega que cruzó la medianoche (o larga a otra ciudad/país): si no hay tripulación en la fecha
        // vigente pero el chofer tiene una ruta EN CURSO de otro día, su asignación NO se le quita — se usa la
        // tripulación de esa ruta abierta, con su fecha, hasta que la ruta se cierre.
        if ($rows === [] && $query->allowOpenCarryover) {
            [$carryRows, $carryDate] = $this->openAssignment($query);
            if ($carryRows !== [] && $carryDate !== null) {
                $rows = $this->mostRecentFirst($carryRows);
                $date = $carryDate;
            }
        }

        if ($rows === []) {
            return null;
        }

        $routes = $this->routesOfViewer($date, $rows);
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
    private function viewerCrews(GetMyCrew $query, string $date): array
    {
        if ($query->driverId !== null && $query->driverId !== '') {
            return $this->directory->list($date, null, $query->driverId, self::MAX_CREWS_PER_DAY, 0)['items'];
        }

        if ($query->helperId !== null && $query->helperId !== '') {
            return $this->directory->listForHelper($date, $query->helperId, self::MAX_CREWS_PER_DAY, 0)['items'];
        }

        return [];
    }

    /**
     * Asignación VIGENTE por RUTA ABIERTA (no por calendario): entre las tripulaciones RECIENTES del
     * solicitante (varios días, más reciente primero), encuentra la más reciente cuyo camión tenga una ruta
     * EN CURSO y devuelve TODAS las tripulaciones de ESE día junto con su fecha. Así una entrega en curso que
     * cruzó la medianoche —o larga a otra ciudad/país— sigue siendo del chofer hasta que la ruta se cierre.
     * Sin ninguna ruta en curso, devuelve `[[], null]` (cae al `null` = sin asignación en la fecha).
     *
     * @return array{0: list<CrewRow>, 1: string|null}
     */
    private function openAssignment(GetMyCrew $query): array
    {
        $recent = $this->recentCrews($query);

        foreach ($recent as $crew) {
            $open = array_filter(
                $this->routes->onDate($crew['assembled_for'], [$crew['vehicle_id']]),
                static fn (array $route): bool => $route['vehicle_id'] === $crew['vehicle_id']
                    && in_array($route['status'], self::CARRYOVER_STATUSES, true),
            );
            if ($open === []) {
                continue;
            }

            $day = $crew['assembled_for'];
            $sameDay = array_values(array_filter(
                $recent,
                static fn (array $c): bool => $c['assembled_for'] === $day,
            ));

            return [$sameDay, $day];
        }

        return [[], null];
    }

    /**
     * Tripulaciones recientes del solicitante (cualquier fecha, más reciente primero), acotadas a RECENT_CREWS.
     *
     * @return list<CrewRow>
     */
    private function recentCrews(GetMyCrew $query): array
    {
        if ($query->driverId !== null && $query->driverId !== '') {
            return $this->directory->list(null, null, $query->driverId, self::RECENT_CREWS, 0)['items'];
        }

        if ($query->helperId !== null && $query->helperId !== '') {
            return $this->directory->listForHelper(null, $query->helperId, self::RECENT_CREWS, 0)['items'];
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
