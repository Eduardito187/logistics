<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cerró un trip: el vehículo dejó de reportar (hueco temporal) o terminó la jornada. Trae el resumen
 * del tramo (distancia, duración, movimiento/inactividad, velocidad máxima y cantidad de puntos).
 */
final readonly class TripCompleted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public ?string $routeId,
        public float $distanceM,
        public int $durationS,
        public int $movingS,
        public int $idleS,
        public float $maxSpeedKph,
        public int $pingCount,
        public string $startedAt,
        public string $endedAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.trip_completed';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'route_id' => $this->routeId,
            'distance_m' => $this->distanceM,
            'duration_s' => $this->durationS,
            'moving_s' => $this->movingS,
            'idle_s' => $this->idleS,
            'max_speed_kph' => $this->maxSpeedKph,
            'ping_count' => $this->pingCount,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
        ];
    }
}
