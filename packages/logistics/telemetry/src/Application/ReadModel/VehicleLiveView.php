<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use DateTimeImmutable;

/**
 * Proyección de lectura de la posición EN VIVO de un vehículo, para pintar la flota en el mapa.
 * Primitivos: no expone objetos de dominio.
 */
final readonly class VehicleLiveView
{
    private function __construct(
        public string $vehicleId,
        public float $lat,
        public float $lng,
        public ?float $speedKph,
        public ?int $heading,
        public ?bool $ignition,
        public bool $moving,
        public ?string $routeId,
        public string $recordedAt,
    ) {}

    /**
     * @param array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['vehicle_id'],
            $row['lat'],
            $row['lng'],
            $row['speed_kph'],
            $row['heading'],
            $row['ignition'],
            $row['moving'],
            $row['route_id'],
            $row['recorded_at']->format(DATE_ATOM),
        );
    }

    /**
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'speed_kph' => $this->speedKph,
            'heading' => $this->heading,
            'ignition' => $this->ignition,
            'moving' => $this->moving,
            'route_id' => $this->routeId,
            'recorded_at' => $this->recordedAt,
        ];
    }
}
