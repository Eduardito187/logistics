<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

use Logistics\Capacity\Domain\CapacityBucket;

/**
 * Modelo de lectura de un bucket de cupo: forma plana y primitiva, lista para serializar a JSON o
 * para la API. No expone objetos de dominio; si se cachea, se cachea su toArray() (regla 7).
 */
final readonly class CapacityView
{
    public function __construct(
        public string $id,
        public ?string $nodeId,
        public ?string $zoneId,
        public ?string $municipalityId,
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
            $bucket->scope()->nodeId,
            $bucket->scope()->zoneId,
            $bucket->scope()->municipalityId,
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
     * @return array{id: string, node_id: string|null, zone_id: string|null, municipality_id: string|null, date: string, time_slot: string, unit: string, total: float, used: float, available: float, full: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->nodeId,
            'zone_id' => $this->zoneId,
            'municipality_id' => $this->municipalityId,
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
