<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Logistics\Capacity\Domain\ActiveHorizon;
use Logistics\Capacity\Domain\Contract\ActiveHorizonRepository;
use Logistics\Capacity\Domain\ValueObject\ActiveHorizonId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Implementación Eloquent del repositorio del horizonte activo de cupos. Mapea el agregado ActiveHorizon
 * hacia y desde la fila capacity_active_horizons (una por municipio, una global con municipio null).
 */
final class EloquentActiveHorizonRepository implements ActiveHorizonRepository
{
    public function nextIdentity(): ActiveHorizonId
    {
        return ActiveHorizonId::generate();
    }

    public function save(ActiveHorizon $horizon): void
    {
        $id = $horizon->id()->toString();

        $record = ActiveHorizonRecord::query()->find($id) ?? new ActiveHorizonRecord;
        $record->id = $id;
        $record->municipality_id = $horizon->municipalityId();
        $record->active_until = $horizon->activeUntil()->toString();
        $record->save();
    }

    public function findFor(?string $municipalityId): ?ActiveHorizon
    {
        $query = ActiveHorizonRecord::query();

        $municipalityId === null
            ? $query->whereNull('municipality_id')
            : $query->where('municipality_id', $municipalityId);

        $record = $query->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(ActiveHorizonRecord $record): ActiveHorizon
    {
        return ActiveHorizon::fromState(
            ActiveHorizonId::fromString($record->id),
            $record->municipality_id,
            // La columna es date; se recorta por si el driver devuelve 'Y-m-d H:i:s'.
            SlotDate::fromString(substr($record->active_until, 0, 10)),
        );
    }
}
