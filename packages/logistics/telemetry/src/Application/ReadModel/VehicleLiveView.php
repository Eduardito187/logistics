<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use DateTimeImmutable;

/**
 * Proyección de lectura de la posición EN VIVO de un vehículo, para pintar la flota en el mapa.
 * Primitivos: no expone objetos de dominio.
 *
 * Enriquecida (aditivo, auditoría #17) con la placa y el chofer actual —resueltos en lote por el handler,
 * sin N+1— y con `updated_at` (hora del servidor en que cambió la posición), que es el cursor del polling
 * incremental `?since=`.
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
        public ?string $plate = null,
        public ?string $driverId = null,
        public ?string $driverName = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: DateTimeImmutable, driver_id?: string|null, updated_at?: DateTimeImmutable|null} $row
     */
    public static function fromRow(array $row, ?string $plate = null, ?string $driverId = null, ?string $driverName = null): self
    {
        $updatedAt = $row['updated_at'] ?? null;

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
            $plate,
            $driverId ?? ($row['driver_id'] ?? null),
            $driverName,
            $updatedAt?->format(DATE_ATOM),
        );
    }

    /**
     * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string, plate: string|null, driver_id: string|null, driver_name: string|null, updated_at: string|null}
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
            'plate' => $this->plate,
            'driver_id' => $this->driverId,
            'driver_name' => $this->driverName,
            'updated_at' => $this->updatedAt,
        ];
    }
}
