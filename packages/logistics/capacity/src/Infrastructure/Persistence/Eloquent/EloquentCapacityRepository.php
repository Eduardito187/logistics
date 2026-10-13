<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Implementación Eloquent del repositorio de cupos. Mapea el agregado CapacityBucket hacia y desde la
 * fila capacity_buckets. total/used se guardan como decimal(12,3); el mapper los pasa por Quantity.
 */
final class EloquentCapacityRepository implements CapacityRepository
{
    public function nextIdentity(): CapacityBucketId
    {
        return CapacityBucketId::generate();
    }

    public function save(CapacityBucket $bucket): void
    {
        $id = $bucket->id()->toString();

        $record = CapacityBucketRecord::query()->find($id) ?? new CapacityBucketRecord();
        $record->id = $id;
        $record->node_id = $bucket->scope()->nodeId;
        $record->zone_id = $bucket->scope()->zoneId;
        $record->slot_date = $bucket->date()->toString();
        $record->time_slot = $bucket->slot()->toString();
        $record->unit = $bucket->unit()->value;
        $record->total = $this->formatQuantity($bucket->total());
        $record->used = $this->formatQuantity($bucket->used());
        $record->save();
    }

    public function findById(CapacityBucketId $id): ?CapacityBucket
    {
        $record = CapacityBucketRecord::query()->find($id->toString());

        return $record === null ? null : $this->toDomain($record);
    }

    public function findBucket(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?CapacityBucket {
        $query = CapacityBucketRecord::query()
            ->where('slot_date', $date->toString())
            ->where('time_slot', $slot->toString())
            ->where('unit', $unit->value);

        // Exactamente una de las dos columnas de scope está poblada; la otra es NULL.
        $scope->nodeId === null
            ? $query->whereNull('node_id')
            : $query->where('node_id', $scope->nodeId);
        $scope->zoneId === null
            ? $query->whereNull('zone_id')
            : $query->where('zone_id', $scope->zoneId);

        $record = $query->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return list<CapacityBucket>
     */
    public function byDate(SlotDate $date): array
    {
        return CapacityBucketRecord::query()
            ->where('slot_date', $date->toString())
            ->orderBy('time_slot')
            ->orderBy('unit')
            ->get()
            ->map(fn (CapacityBucketRecord $record): CapacityBucket => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(CapacityBucketRecord $record): CapacityBucket
    {
        return CapacityBucket::fromState(
            CapacityBucketId::fromString($record->id),
            BucketScope::fromNullable($record->node_id, $record->zone_id),
            // La columna es date; se recorta por si el driver devuelve 'Y-m-d H:i:s'.
            SlotDate::fromString(substr($record->slot_date, 0, 10)),
            TimeSlot::fromString($record->time_slot),
            CapacityUnit::from($record->unit),
            Quantity::of((float) $record->total),
            Quantity::of((float) $record->used),
        );
    }

    /**
     * Formatea una Quantity como decimal con 3 decimales, el formato exacto de la columna decimal(12,3).
     */
    private function formatQuantity(Quantity $quantity): string
    {
        return number_format($quantity->value(), 3, '.', '');
    }
}
