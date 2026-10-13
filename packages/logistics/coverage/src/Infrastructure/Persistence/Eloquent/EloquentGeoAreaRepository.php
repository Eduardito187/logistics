<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Logistics\Coverage\Domain\Contract\GeoAreaRepository;
use Logistics\Coverage\Domain\Model\GeoArea;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;

/**
 * Implementación Eloquent del repositorio de la jerarquía administrativa. Sin geometría: columnas
 * ordinarias, así que se usa el modelo directamente.
 */
final class EloquentGeoAreaRepository implements GeoAreaRepository
{
    public function nextIdentity(): GeoAreaId
    {
        return GeoAreaId::generate();
    }

    public function save(GeoArea $area): void
    {
        GeoAreaRecord::query()->updateOrCreate(
            ['id' => $area->id()->toString()],
            [
                'name' => $area->name(),
                'level' => $area->level()->value,
                'parent_id' => $area->parentId()?->toString(),
            ],
        );
    }

    public function findById(GeoAreaId $id): ?GeoArea
    {
        $record = GeoAreaRecord::query()->find($id->toString());

        return $record instanceof GeoAreaRecord ? $this->toDomain($record) : null;
    }

    public function findByNameAndLevel(string $name, AdminLevel $level): ?GeoArea
    {
        $record = GeoAreaRecord::query()
            ->where('name', $name)
            ->where('level', $level->value)
            ->first();

        return $record instanceof GeoAreaRecord ? $this->toDomain($record) : null;
    }

    /**
     * @return array<int, GeoArea>
     */
    public function all(): array
    {
        return GeoAreaRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (GeoAreaRecord $record): GeoArea => $this->toDomain($record))
            ->all();
    }

    private function toDomain(GeoAreaRecord $record): GeoArea
    {
        return GeoArea::create(
            GeoAreaId::fromString($record->id),
            $record->name,
            AdminLevel::from($record->level),
            $record->parent_id !== null ? GeoAreaId::fromString($record->parent_id) : null,
        );
    }
}
