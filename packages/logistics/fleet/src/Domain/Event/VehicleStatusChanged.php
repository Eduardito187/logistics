<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambió el estado operativo de un vehículo (p. ej. pasó a mantenimiento o volvió a servicio).
 */
final readonly class VehicleStatusChanged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $from,
        public string $to,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.vehicle_status_changed';
    }

    public function payload(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }
}
