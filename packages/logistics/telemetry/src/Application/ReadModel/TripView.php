<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use Logistics\Telemetry\Domain\Model\Trip;

/**
 * Proyección de lectura de un trip para listados y API. Además de las métricas crudas agrega valores
 * legibles para el humano: kilómetros y minutos.
 */
final readonly class TripView
{
    private function __construct(
        public string $id,
        public string $vehicleId,
        public ?string $driverId,
        public ?string $routeId,
        public string $status,
        public string $startedAt,
        public ?string $endedAt,
        public float $distanceM,
        public int $durationS,
        public int $movingS,
        public int $idleS,
        public float $maxSpeedKph,
        public int $pingCount,
        public float $distanceKm,
        public int $durationMin,
        public int $movingMin,
        public int $idleMin,
    ) {}

    public static function fromDomain(Trip $trip): self
    {
        return new self(
            $trip->id()->toString(),
            $trip->vehicleId(),
            $trip->driverId(),
            $trip->routeId(),
            $trip->status()->value,
            $trip->startedAt()->format(DATE_ATOM),
            $trip->endedAt()?->format(DATE_ATOM),
            $trip->distanceM(),
            $trip->durationS(),
            $trip->movingS(),
            $trip->idleS(),
            $trip->maxSpeedKph(),
            $trip->pingCount(),
            round($trip->distanceM() / 1000, 2),
            intdiv($trip->durationS(), 60),
            intdiv($trip->movingS(), 60),
            intdiv($trip->idleS(), 60),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, route_id: string|null, status: string, started_at: string, ended_at: string|null, distance_m: float, duration_s: int, moving_s: int, idle_s: int, max_speed_kph: float, ping_count: int, distance_km: float, duration_min: int, moving_min: int, idle_min: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'route_id' => $this->routeId,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            'distance_m' => $this->distanceM,
            'duration_s' => $this->durationS,
            'moving_s' => $this->movingS,
            'idle_s' => $this->idleS,
            'max_speed_kph' => $this->maxSpeedKph,
            'ping_count' => $this->pingCount,
            'distance_km' => $this->distanceKm,
            'duration_min' => $this->durationMin,
            'moving_min' => $this->movingMin,
            'idle_min' => $this->idleMin,
        ];
    }
}
