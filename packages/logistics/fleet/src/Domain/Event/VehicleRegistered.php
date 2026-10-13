<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un vehículo nuevo en la flota.
 */
final readonly class VehicleRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $plate,
        public int $capacityKg,
        public float $capacityM3,
        public string $homeBaseNodeId,
        public string $status,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.vehicle_registered';
    }

    public function payload(): array
    {
        return [
            'plate' => $this->plate,
            'capacity_kg' => $this->capacityKg,
            'capacity_m3' => $this->capacityM3,
            'home_base_node_id' => $this->homeBaseNodeId,
            'status' => $this->status,
        ];
    }
}
