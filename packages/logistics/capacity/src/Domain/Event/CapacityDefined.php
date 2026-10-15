<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió un cupo nuevo (un bucket) para un nodo/zona, fecha, franja y unidad. Lo consumen
 * planning (para saber qué capacidad hay) y la auditoría, vía outbox.
 */
final readonly class CapacityDefined extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private ?string $nodeId,
        private ?string $zoneId,
        private string $date,
        private string $timeSlot,
        private string $unit,
        private float $total,
        private ?string $municipalityId = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.capacity_defined';
    }

    /**
     * @return array{node_id: string|null, zone_id: string|null, municipality_id: string|null, date: string, time_slot: string, unit: string, total: float}
     */
    public function payload(): array
    {
        return [
            'node_id' => $this->nodeId,
            'zone_id' => $this->zoneId,
            'municipality_id' => $this->municipalityId,
            'date' => $this->date,
            'time_slot' => $this->timeSlot,
            'unit' => $this->unit,
            'total' => $this->total,
        ];
    }
}
