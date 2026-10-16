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
        private string $scopeKind = 'dispatch',
        private ?string $warehouseId = null,
        private ?string $dockId = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.capacity_defined';
    }

    /**
     * El eje de recepción (scope_kind/warehouse_id/dock_id) se agregó de forma aditiva para el espejo
     * inbound; para los cupos de despacho scope_kind es 'dispatch' y el almacén/andén van en null.
     *
     * @return array{node_id: string|null, zone_id: string|null, municipality_id: string|null, date: string, time_slot: string, unit: string, total: float, scope_kind: string, warehouse_id: string|null, dock_id: string|null}
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
            'scope_kind' => $this->scopeKind,
            'warehouse_id' => $this->warehouseId,
            'dock_id' => $this->dockId,
        ];
    }
}
