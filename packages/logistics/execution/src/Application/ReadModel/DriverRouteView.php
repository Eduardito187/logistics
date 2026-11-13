<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use DateTimeInterface;
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
 */
final readonly class DriverRouteView
{
    /**
     * @param list<RouteStopView>       $stops
     * @param array<string, mixed>|null $routeGeometry GeoJSON LineString + totales (esquema RouteGeometry) o null
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
    ) {}

    /**
     * Construye la vista desde el agregado, con el enriquecimiento cross-context opcional.
     *
     * @param array<string, StopEnrichment> $enrichmentByShipment mapa id de envío => datos del envío de cada parada
     * @param array<string, mixed>|null     $routeGeometry        geometría de la ruta (esquema RouteGeometry) o null
     */
    public static function fromRoute(
        VehicleRoute $route,
        array $enrichmentByShipment = [],
        ?array $routeGeometry = null,
    ): self {
        $stops = [];
        foreach ($route->stops() as $stop) {
            $stops[] = self::enrichStop($stop, $enrichmentByShipment);
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
            $current === null ? null : self::enrichStop($current, $enrichmentByShipment),
            $stops,
            $routeGeometry,
            $route->startedAt()?->format(DateTimeInterface::ATOM),
            $route->closedAt()?->format(DateTimeInterface::ATOM),
            $route->startOdometerKm(),
            $route->closeOdometerKm(),
            $route->failedCount(),
            $route->rescheduledCount(),
        );
    }

    /**
     * Construye la vista de una parada aplicando su enriquecimiento (si lo hay) por id de envío.
     *
     * @param array<string, StopEnrichment> $enrichmentByShipment
     */
    public static function enrichStop(
        RouteStop $stop,
        array $enrichmentByShipment,
        ?string $podConfirmation = null,
        ?string $podPhotoUrl = null,
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
        ];
    }
}
