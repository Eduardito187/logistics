<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se levantó una alerta de mal uso sobre la telemetría de un vehículo.
 */
final readonly class AlertRaised extends BaseDomainEvent
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $type,
        public string $severity,
        // Momento en que ocurrió el hecho de la alerta (ISO 8601). Distinto de `occurredAt` del evento base
        // (cuándo se creó el evento); por eso lleva otro nombre y no sombrea la propiedad del padre.
        public string $alertOccurredAtIso,
        public array $detail,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.alert_raised';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'type' => $this->type,
            'severity' => $this->severity,
            'occurred_at' => $this->alertOccurredAtIso,
            'detail' => $this->detail,
        ];
    }
}
