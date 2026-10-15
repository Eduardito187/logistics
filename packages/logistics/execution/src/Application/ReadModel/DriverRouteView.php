<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\VehicleRoute;

/**
 * Modelo de lectura de la ruta del chofer: forma plana en primitivos para la app ("mi ruta") y el panel.
 *
 * Expone la secuencia completa de paradas, la PARADA ACTUAL (la siguiente a atender) y cuántas quedan,
 * para que el chofer sepa dónde está parado y el cliente a cuántas paradas está su pedido.
 */
final readonly class DriverRouteView
{
    /**
     * @param list<RouteStopView> $stops
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
    ) {}

    public static function fromRoute(VehicleRoute $route): self
    {
        $stops = [];
        foreach ($route->stops() as $stop) {
            $stops[] = RouteStopView::fromStop($stop);
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
            $current === null ? null : RouteStopView::fromStop($current),
            $stops,
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
        ];
    }
}
