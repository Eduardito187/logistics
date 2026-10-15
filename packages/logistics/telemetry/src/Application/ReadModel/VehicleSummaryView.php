<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

/**
 * Resumen de telemetría de un vehículo en un día: totales agregados a partir de sus trips.
 */
final readonly class VehicleSummaryView
{
    public function __construct(
        public string $vehicleId,
        public string $date,
        public float $distanceM,
        public int $movingS,
        public int $idleS,
        public int $trips,
        public float $maxSpeedKph,
        public ?string $firstRecordedAt,
        public ?string $lastRecordedAt,
    ) {}

    /**
     * @return array{vehicle_id: string, date: string, distance_m: float, moving_s: int, idle_s: int, trips: int, max_speed_kph: float, first_recorded_at: string|null, last_recorded_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'date' => $this->date,
            'distance_m' => $this->distanceM,
            'moving_s' => $this->movingS,
            'idle_s' => $this->idleS,
            'trips' => $this->trips,
            'max_speed_kph' => $this->maxSpeedKph,
            'first_recorded_at' => $this->firstRecordedAt,
            'last_recorded_at' => $this->lastRecordedAt,
        ];
    }
}
