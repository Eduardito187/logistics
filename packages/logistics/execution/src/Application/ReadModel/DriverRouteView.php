<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use DateTimeInterface;
use Logistics\Execution\Application\Contract\LiveRouteStateReader;
use Logistics\Execution\Application\Contract\StopEnrichmentReader;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;

/**
 * Modelo de lectura de la ruta del chofer: forma plana en primitivos para la app ("mi ruta") y el panel.
 *
 * Expone la secuencia completa de paradas, la PARADA ACTUAL (la siguiente a atender) y cuántas quedan,
 * para que el chofer sepa dónde está parado y el cliente a cuántas paradas está su pedido.
 *
 * Lleva además la GEOMETRÍA de la ruta por calles (GeoJSON LineString, forma primitiva), enriquecida
 * cross-context desde el plan publicado (planning). Es nula hasta que el ruteo la calcula; el dominio de
 * ejecución no la conoce (la resuelve el QueryHandler por lectura directa).
 *
 * Ciclo de vida real de la jornada: `started_at` / `closed_at` (inicio y cierre informados por el chofer).
 *
 * @phpstan-import-type StopEnrichment from StopEnrichmentReader
 * @phpstan-import-type LiveRouteState from LiveRouteStateReader
 */
final readonly class DriverRouteView
{
    /**
     * `offRoute` es la verdad del backend sobre si el camión está FUERA de su ruta planificada (desvío
     * sostenido), que calcula el motor único de ETA; `offRouteSince` es desde cuándo (ISO 8601) o null.
     * Reemplazan al cálculo local que hacía la app para el cartel "Fuera de ruta".
     *
     * `rerouteGeometry` es la polilínea (lista de `{lat, lng}`) desde la posición ACTUAL del camión hasta su
     * próxima parada pendiente, que calcula el motor único de ETA, para que la app REDIBUJE la ruta cuando está
     * fuera de ruta. Solo viaja cuando `offRoute` es true; si no, null (la app dibuja la polilínea planificada).
     *
     * @param list<RouteStopView>                      $stops
     * @param array<string, mixed>|null                $routeGeometry   GeoJSON LineString + totales (esquema RouteGeometry) o null
     * @param list<array{lat: float, lng: float}>|null $rerouteGeometry polilínea del reruteo (fuera de ruta) o null
     */
    public function __construct(
        public string $id,
        public string $vehicleId,
        public string $routeDate,
        public string $status,
        public int $totalStops,
        public int $completedStops,
        public int $stopsRemaining,
        public ?RouteStopView $currentStop,
        public array $stops,
        public ?array $routeGeometry = null,
        public ?string $startedAt = null,
        public ?string $closedAt = null,
        public ?float $startOdometerKm = null,
        public ?float $closeOdometerKm = null,
        // `completedStops` cuenta SOLO entregadas; estas dos exponen los otros desenlaces por separado para
        // que la app no confunda un fallo/replanificación con una entrega.
        public int $failedStops = 0,
        public int $rescheduledStops = 0,
        public bool $offRoute = false,
        public ?string $offRouteSince = null,
        public ?array $rerouteGeometry = null,
    ) {}

    /**
     * Construye la vista desde el agregado, con el enriquecimiento cross-context opcional y, si el motor de
     * ETA lo entregó, la ETA viva por parada y el estado "fuera de ruta".
     *
     * @param array<string, StopEnrichment> $enrichmentByShipment mapa id de envío => datos del envío de cada parada
     * @param array<string, mixed>|null     $routeGeometry        geometría de la ruta (esquema RouteGeometry) o null
     * @param LiveRouteState|null           $liveState            estado en vivo del motor único de ETA o null
     */
    public static function fromRoute(
        VehicleRoute $route,
        array $enrichmentByShipment = [],
        ?array $routeGeometry = null,
        ?array $liveState = null,
    ): self {
        $liveEtas = $liveState === null ? [] : $liveState['etas'];
        $offRoute = $liveState !== null && $liveState['off_route'];
        $offRouteSince = $liveState !== null && is_string($liveState['off_route_since']) ? $liveState['off_route_since'] : null;
        // La geometría del reruteo solo aplica fuera de ruta: así la app no la dibuja cuando el camión ya volvió.
        $rerouteGeometry = $liveState !== null && $offRoute ? $liveState['reroute_geometry'] : null;

        $stops = [];
        foreach ($route->stops() as $stop) {
            $stops[] = self::enrichStop($stop, $enrichmentByShipment, liveEtas: $liveEtas);
        }

        $current = $route->currentStop();

        return new self(
            $route->id()->toString(),
            $route->vehicleId(),
            $route->date()->toString(),
            $route->status()->value,
            $route->totalStops(),
            // Completadas = ENTREGADAS con éxito (no las fallidas/replanificadas: esas van aparte).
            $route->deliveredCount(),
            $route->stopsRemaining(),
            $current === null ? null : self::enrichStop($current, $enrichmentByShipment, liveEtas: $liveEtas),
            $stops,
            $routeGeometry,
            $route->startedAt()?->format(DateTimeInterface::ATOM),
            $route->closedAt()?->format(DateTimeInterface::ATOM),
            $route->startOdometerKm(),
            $route->closeOdometerKm(),
            $route->failedCount(),
            $route->rescheduledCount(),
            $offRoute,
            $offRouteSince,
            $rerouteGeometry,
        );
    }

    /**
     * Construye la vista de una parada aplicando su enriquecimiento (si lo hay) por id de envío y, cuando el
     * motor de ETA entregó una ETA viva para ese envío, superponiéndola sobre la planificada.
     *
     * @param array<string, StopEnrichment> $enrichmentByShipment
     * @param array<string, string>         $liveEtas             ETA viva por id de envío (ISO 8601)
     */
    public static function enrichStop(
        RouteStop $stop,
        array $enrichmentByShipment,
        ?string $podConfirmation = null,
        ?string $podPhotoUrl = null,
        array $liveEtas = [],
    ): RouteStopView {
        $data = $enrichmentByShipment[$stop->shipmentId()] ?? null;

        return RouteStopView::fromStop(
            $stop,
            $data['customer_phone'] ?? null,
            $data['address_reference'] ?? null,
            $data['items'] ?? [],
            $data['destination'] ?? null,
            $data['unit_ids'] ?? [],
            $podConfirmation,
            $podPhotoUrl,
            $data['lat'] ?? null,
            $data['lng'] ?? null,
            $liveEtas[$stop->shipmentId()] ?? null,
        );
    }

    /**
     * Forma corta del ciclo de vida (respuesta de start/close): sin paradas ni geometría.
     *
     * @return array<string, mixed>
     */
    public function lifecycleArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'route_date' => $this->routeDate,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'closed_at' => $this->closedAt,
            'start_odometer_km' => $this->startOdometerKm,
            'close_odometer_km' => $this->closeOdometerKm,
            'total_stops' => $this->totalStops,
            'completed_stops' => $this->completedStops,
            'failed_stops' => $this->failedStops,
            'rescheduled_stops' => $this->rescheduledStops,
            'stops_remaining' => $this->stopsRemaining,
            // Paradas que quedaron sin atender (el cierre no las exige): mismo número que stops_remaining.
            'pending_stops' => $this->stopsRemaining,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'route_date' => $this->routeDate,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'closed_at' => $this->closedAt,
            'total_stops' => $this->totalStops,
            'completed_stops' => $this->completedStops,
            'failed_stops' => $this->failedStops,
            'rescheduled_stops' => $this->rescheduledStops,
            'stops_remaining' => $this->stopsRemaining,
            'current_stop' => $this->currentStop?->toArray(),
            'stops' => array_map(static fn (RouteStopView $stop): array => $stop->toArray(), $this->stops),
            'route_geometry' => $this->routeGeometry,
            // Verdad del backend para el cartel "Fuera de ruta" (antes lo decidía la app en local).
            'off_route' => $this->offRoute,
            'off_route_since' => $this->offRouteSince,
            // Polilínea para REDIBUJAR la ruta cuando está fuera de ruta (posición actual → próxima parada); si no, null.
            'reroute_geometry' => $this->rerouteGeometry,
        ];
    }
}
