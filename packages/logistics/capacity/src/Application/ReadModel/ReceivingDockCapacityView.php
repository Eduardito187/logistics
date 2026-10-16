<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

use Logistics\Capacity\Domain\CapacityBucket;

/**
 * Modelo de lectura de un cupo de RECEPCIÓN (andén de un almacén central): forma plana y primitiva,
 * lista para serializar a JSON o para el cockpit de inbound. No expone objetos de dominio; si se cachea,
 * se cachea su toArray() (regla 7).
 *
 * Es el espejo de CapacityView para el eje inbound; se mantiene separado para no mezclar la forma del
 * cupo de despacho (node/zone/municipality) con la de recepción (warehouse/dock).
 */
final readonly class ReceivingDockCapacityView
{
    public function __construct(
        public string $id,
        public string $warehouseId,
        public ?string $dockId,
        public string $date,
        public string $timeSlot,
        public string $unit,
        public float $total,
        public float $used,
        public float $available,
        public bool $full,
    ) {}

    public static function fromBucket(CapacityBucket $bucket): self
    {
        return new self(
            $bucket->id()->toString(),
            // El almacén nunca es null en un cupo inbound (lo garantiza BucketScope::forReceivingDock);
            // el cast deja el tipo en string sin disparar un falso nullable en el ReadModel.
            (string) $bucket->scope()->warehouseId,
            $bucket->scope()->dockId,
            $bucket->date()->toString(),
            $bucket->slot()->toString(),
            $bucket->unit()->value,
            $bucket->total()->value(),
            $bucket->used()->value(),
            $bucket->available()->value(),
            $bucket->isFull(),
        );
    }

    /**
     * @return array{id: string, warehouse_id: string, dock_id: string|null, date: string, time_slot: string, unit: string, total: float, used: float, available: float, full: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouseId,
            'dock_id' => $this->dockId,
            'date' => $this->date,
            'time_slot' => $this->timeSlot,
            'unit' => $this->unit,
            'total' => $this->total,
            'used' => $this->used,
            'available' => $this->available,
            'full' => $this->full,
        ];
    }
}
