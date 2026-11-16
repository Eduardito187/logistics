<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

/**
 * Proyección de lectura de la ETA dinámica de las paradas restantes de un vehículo. Primitivos.
 *
 * `traffic_aware` es siempre false: no hay fuente de tráfico en vivo; la ETA sale de la posición actual y de
 * duraciones viales OSRM (si hay backend) o de distancia en línea recta / velocidad media.
 *
 * @phpstan-type StopEtaItem array{shipment_id: string, sequence: int, order_reference: string|null, customer_name: string|null, lat: float|null, lng: float|null, planned_eta: string|null, eta: string|null, remaining_distance_m: int|null, remaining_duration_s: int|null, method: string|null, computed_at: string|null}
 * @phpstan-type PositionItem array{lat: float, lng: float, recorded_at: string}
 */
final readonly class VehicleEtaView
{
    /**
     * `offRoute` es la verdad del backend sobre si el camión está FUERA de su ruta planificada (desvío
     * sostenido más allá del umbral + histéresis de la config de telemetría); `offRouteSince` es desde
     * cuándo (ISO 8601) o null. Son el estado que la app muestra como cartel "Fuera de ruta".
     *
     * @param PositionItem|null $position
     * @param list<StopEtaItem> $stops
     */
    public function __construct(
        public string $vehicleId,
        public ?string $plate,
        public ?array $position,
        public bool $positionStale,
        public string $generatedAt,
        public array $stops,
        public bool $offRoute = false,
        public ?string $offRouteSince = null,
    ) {}

    /**
     * Misma vista con solo la parada del envío pedido (o ninguna).
     */
    public function onlyShipment(string $shipmentId): self
    {
        return new self(
            $this->vehicleId,
            $this->plate,
            $this->position,
            $this->positionStale,
            $this->generatedAt,
            array_values(array_filter($this->stops, static fn (array $s): bool => $s['shipment_id'] === $shipmentId)),
            $this->offRoute,
            $this->offRouteSince,
        );
    }

    /**
     * @return array{vehicle_id: string, plate: string|null, traffic_aware: bool, position: PositionItem|null, position_stale: bool, off_route: bool, off_route_since: string|null, generated_at: string, stops: list<StopEtaItem>}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'plate' => $this->plate,
            'traffic_aware' => false,
            'position' => $this->position,
            'position_stale' => $this->positionStale,
            'off_route' => $this->offRoute,
            'off_route_since' => $this->offRouteSince,
            'generated_at' => $this->generatedAt,
            'stops' => $this->stops,
        ];
    }
}
