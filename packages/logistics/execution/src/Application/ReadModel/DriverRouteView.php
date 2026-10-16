<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

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
    ) {}

    /**
     * Construye la vista desde el agregado, con el enriquecimiento cross-context opcional.
     *
     * @param array<string, array{customer_phone: string|null, address_reference: string|null, items: list<array{sku: string, name: string, quantity: int, image_url: string|null}>}> $enrichmentByShipment
     *                                                                                                                                                                                                      mapa id de envío => datos del envío (teléfono, referencia, líneas) para cada parada
     * @param array<string, mixed>|null                                                                                                                                               $routeGeometry        geometría de la ruta (esquema RouteGeometry) o null
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
            $route->settledCount(),
            $route->stopsRemaining(),
            $current === null ? null : self::enrichStop($current, $enrichmentByShipment),
            $stops,
            $routeGeometry,
        );
    }

    /**
     * Construye la vista de una parada aplicando su enriquecimiento (si lo hay) por id de envío.
     *
     * @param array<string, array{customer_phone: string|null, address_reference: string|null, items: list<array{sku: string, name: string, quantity: int, image_url: string|null}>}> $enrichmentByShipment
     */
    private static function enrichStop(RouteStop $stop, array $enrichmentByShipment): RouteStopView
    {
        $data = $enrichmentByShipment[$stop->shipmentId()] ?? null;

        return RouteStopView::fromStop(
            $stop,
            $data['customer_phone'] ?? null,
            $data['address_reference'] ?? null,
            $data['items'] ?? [],
        );
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
            'total_stops' => $this->totalStops,
            'completed_stops' => $this->completedStops,
            'stops_remaining' => $this->stopsRemaining,
            'current_stop' => $this->currentStop?->toArray(),
            'stops' => array_map(static fn (RouteStopView $stop): array => $stop->toArray(), $this->stops),
            'route_geometry' => $this->routeGeometry,
        ];
    }
}
