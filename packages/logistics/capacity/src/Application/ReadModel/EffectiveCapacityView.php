<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Modelo de lectura de la capacidad EFECTIVA de una clave (scope/fecha/franja/unidad): el disponible
 * real, venga de un bucket ya definido o del total por defecto de las reglas configurables.
 *
 * `source` dice de dónde salió el número: 'bucket' (hay cupo explícito), 'rule' (no hay cupo, pero las
 * reglas dan un total por defecto, con consumo cero) o 'none' (ni cupo ni regla; no hay capacidad
 * conocida). Forma plana y primitiva: si se cachea, se cachea su toArray() (regla 7).
 */
final readonly class EffectiveCapacityView
{
    public function __construct(
        public ?string $bucketId,
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
        public string $source,
    ) {}

    /**
     * Capacidad efectiva tomada de un cupo ya definido.
     */
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
            'bucket',
        );
    }

    /**
     * Capacidad efectiva derivada de una regla por defecto (no hay cupo explícito): consumo cero.
     */
    public static function fromRule(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
        Quantity $total,
    ): self {
        return new self(
            null,
            $scope->nodeId,
            $scope->zoneId,
            $scope->municipalityId,
            $date->toString(),
            $slot->toString(),
            $unit->value,
            $total->value(),
            0.0,
            $total->value(),
            $total->isZero(),
            'rule',
        );
    }

    /**
     * No hay ni cupo ni regla aplicable: capacidad desconocida (todo en cero).
     */
    public static function none(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): self {
        return new self(
            null,
            $scope->nodeId,
            $scope->zoneId,
            $scope->municipalityId,
            $date->toString(),
            $slot->toString(),
            $unit->value,
            0.0,
            0.0,
            0.0,
            true,
            'none',
        );
    }

    /**
     * @return array{bucket_id: string|null, node_id: string|null, zone_id: string|null, municipality_id: string|null, date: string, time_slot: string, unit: string, total: float, used: float, available: float, full: bool, source: string}
     */
    public function toArray(): array
    {
        return [
            'bucket_id' => $this->bucketId,
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
            'source' => $this->source,
        ];
    }
}
