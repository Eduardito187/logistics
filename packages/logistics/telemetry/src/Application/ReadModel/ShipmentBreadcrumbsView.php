<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

/**
 * Proyección de lectura de las migas de pan de un envío: ventana a bordo + traza reducida. Primitivos.
 *
 * `window.from` = primer escaneo de carga; `window.to` = entrega/fallo o, si sigue a bordo, el instante de la
 * consulta (`window.open = true`). `points_total` es la cantidad de lecturas crudas de la ventana antes de reducir.
 *
 * @phpstan-type BreadcrumbPoint array{lat: float, lng: float, speed_kph: float|null, heading: int|null, recorded_at: string}
 */
final readonly class ShipmentBreadcrumbsView
{
    /**
     * @param list<BreadcrumbPoint> $points
     */
    public function __construct(
        public string $shipmentId,
        public string $vehicleId,
        public ?string $plate,
        public string $deliveryStatus,
        public ?string $from,
        public ?string $to,
        public bool $open,
        public int $pointsTotal,
        public array $points,
    ) {}

    /**
     * @return array{shipment_id: string, vehicle_id: string, plate: string|null, delivery_status: string, window: array{from: string|null, to: string|null, open: bool}, points_total: int, points_returned: int, points: list<BreadcrumbPoint>}
     */
    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
            'plate' => $this->plate,
            'delivery_status' => $this->deliveryStatus,
            'window' => ['from' => $this->from, 'to' => $this->to, 'open' => $this->open],
            'points_total' => $this->pointsTotal,
            'points_returned' => count($this->points),
            'points' => $this->points,
        ];
    }
}
