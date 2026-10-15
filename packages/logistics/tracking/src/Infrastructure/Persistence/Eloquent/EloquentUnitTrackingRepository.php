<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

/**
 * Implementación Eloquent del repositorio de seguimiento per-bulto.
 *
 * save() es un upsert por id del seguimiento del bulto; la unicidad de unit_id la respalda el índice
 * único de la tabla.
 */
final class EloquentUnitTrackingRepository implements UnitTrackingRepository
{
    public function __construct(
        private readonly UnitTrackingMapper $mapper,
    ) {}

    public function nextIdentity(): UnitTrackingId
    {
        return UnitTrackingId::generate();
    }

    public function save(UnitTracking $unit): void
    {
        UnitTrackingRecord::query()->updateOrCreate(
            ['id' => $unit->id()->toString()],
            $this->mapper->toRow($unit),
        );
    }

    public function findByUnitId(string $unitId): ?UnitTracking
    {
        $record = UnitTrackingRecord::query()->where('unit_id', $unitId)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function listByShipmentId(string $shipmentId): array
    {
        $records = UnitTrackingRecord::query()
            ->where('shipment_id', $shipmentId)
            ->orderBy('unit_id')
            ->get();

        return $records
            ->map(fn (UnitTrackingRecord $record): UnitTracking => $this->mapper->toDomain($record))
            ->all();
    }
}
