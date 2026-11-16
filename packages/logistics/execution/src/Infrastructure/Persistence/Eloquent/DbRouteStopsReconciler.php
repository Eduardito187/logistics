<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;
use Logistics\Execution\Application\Contract\PlannedRouteStopsProvider;
use Logistics\Execution\Application\Contract\RouteStopsReconciler;
use Logistics\Execution\Application\Service\StaleRouteStops;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteId;
use stdClass;
use Throwable;

/**
 * Saneo de las paradas de más en las rutas NO cerradas, con el query builder para ubicar las rutas y el
 * agregado VehicleRoute para quitar las paradas y recalcular su estado. Recorre las rutas con `closed_at`
 * nulo por lotes (cursor por id), decide qué paradas sobran con {@see StaleRouteStops} (plan publicado del
 * camión/día + cerradas antes del día de la ruta) y, al aplicar, guarda el agregado saneado.
 *
 * Guarda extra conservadora: una parada cuyo envío tuvo un escaneo de terreno ese mismo día no se quita,
 * aunque el plan ya no la liste (hubo trabajo real en ella esa jornada). El save del repositorio regenera
 * las filas de paradas sin reemitir eventos al outbox: el saneo no es un hecho de negocio.
 *
 * @phpstan-import-type ReconcileReport from RouteStopsReconciler
 */
final readonly class DbRouteStopsReconciler implements RouteStopsReconciler
{
    /** Cursor inicial: un UUID válido (todo ceros) para no comparar texto no-UUID contra la columna uuid. */
    private const string ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private ConnectionInterface $db,
        private VehicleRouteRepository $routes,
        private PlannedRouteStopsProvider $plannedStops,
        private StaleRouteStops $staleStops,
    ) {}

    /**
     * @return ReconcileReport
     */
    public function reconcile(bool $apply, int $chunk): array
    {
        $chunk = max(1, $chunk);
        $cursor = self::ZERO_UUID;
        $scanned = 0;
        $touched = 0;
        $removed = 0;

        do {
            $ids = $this->nextRouteIds($cursor, $chunk);
            foreach ($ids as $id) {
                $cursor = $id;

                $route = $this->routes->findById(RouteId::fromString($id));
                if ($route === null || $route->isClosed()) {
                    continue;
                }

                $scanned++;
                $stale = $this->staleShipmentIds($route);
                if ($stale === []) {
                    continue;
                }

                $touched++;
                $removed += $apply ? $this->applyCleanup($route, $stale) : count($stale);
            }
            $more = count($ids) === $chunk;
        } while ($more);

        return ['routes_scanned' => $scanned, 'routes_touched' => $touched, 'stops_removed' => $removed];
    }

    /**
     * Quita las paradas de más de la ruta, recalcula su estado y la guarda. El save regenera las filas de
     * paradas sin reemitir eventos al outbox. Devuelve cuántas paradas quitó.
     *
     * @param list<string> $stale
     */
    private function applyCleanup(VehicleRoute $route, array $stale): int
    {
        $removed = $route->dropStops($stale);
        $route->recalculateStatusAfterCleanup();
        $this->routes->save($route);

        return $removed;
    }

    /**
     * Envíos cuyas paradas sobran en la ruta, sin las que tuvieron un escaneo de terreno ese mismo día.
     *
     * @return list<string>
     */
    private function staleShipmentIds(VehicleRoute $route): array
    {
        $planned = $this->plannedShipmentIds($route->vehicleId(), $route->date()->toString());
        $stale = $this->staleStops->staleIn($route, $planned);
        if ($stale === []) {
            return [];
        }

        $scanned = $this->shipmentsScannedOn($stale, $route->date()->toString());

        return array_values(array_filter($stale, static fn (string $id): bool => !in_array($id, $scanned, true)));
    }

    /**
     * Ids de envío del plan publicado del camión para esa fecha, o null si no hay plan publicado que
     * comparar (el saneo omite entonces el criterio del plan, por las dudas).
     *
     * @return list<string>|null
     */
    private function plannedShipmentIds(string $vehicleId, string $routeDate): ?array
    {
        $inputs = $this->plannedStops->forVehicleOnDate($vehicleId, $routeDate);
        if ($inputs === []) {
            return null;
        }

        return array_values(array_map(static fn (RouteStopInput $input): string => $input->shipmentId, $inputs));
    }

    /**
     * De los envíos dados, los que tienen al menos un escaneo de terreno (execution_scan_events) en el día
     * de la ruta: hubo trabajo real en esa parada esa jornada, así que no se quita aunque el plan no la liste.
     *
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    private function shipmentsScannedOn(array $shipmentIds, string $routeDay): array
    {
        if ($shipmentIds === []) {
            return [];
        }

        try {
            $rows = $this->db->table('execution_scan_events as e')
                ->join('execution_deliveries as d', 'd.id', '=', 'e.delivery_id')
                ->whereIn('d.shipment_id', $shipmentIds)
                ->get(['d.shipment_id', 'e.occurred_at'])
                ->all();
        } catch (Throwable) {
            return [];
        }

        $scanned = [];
        foreach ($rows as $row) {
            if ($row instanceof stdClass && StaleRouteStops::dayOf($row->occurred_at ?? null) === $routeDay) {
                $scanned[(string) $row->shipment_id] = true;
            }
        }

        return array_keys($scanned);
    }

    /**
     * Siguiente lote de ids de rutas NO cerradas, en orden de id a partir del cursor.
     *
     * @return list<string>
     */
    private function nextRouteIds(string $cursor, int $chunk): array
    {
        $ids = $this->db->table('execution_driver_routes')
            ->whereNull('closed_at')
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($chunk)
            ->pluck('id')
            ->all();

        return array_values(array_map(static fn (mixed $id): string => (string) $id, $ids));
    }
}
