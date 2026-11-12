<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El vehículo se alejó de la geometría de su ruta planificada más del umbral durante el tiempo mínimo
 * configurado. Se emite junto con `telemetry.alert_raised` (mismo agregado: la alerta), para que los
 * consumidores (notificaciones, SAP) puedan suscribirse a este hecho concreto sin filtrar por tipo.
 */
final readonly class VehicleRouteDeviated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public ?float $lat,
        public ?float $lng,
        public ?float $distanceM,
        public ?int $sustainedSeconds,
        public string $deviatedAtIso,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.vehicle_route_deviated';
    }

    public function payload(): array
    {
        return [
            'alert_id' => $this->aggregateId,
            'vehicle_id' => $this->vehicleId,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'distance_m' => $this->distanceM,
            'sustained_seconds' => $this->sustainedSeconds,
            'occurred_at' => $this->deviatedAtIso,
        ];
    }
}
