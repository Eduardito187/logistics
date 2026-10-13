<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;

/**
 * Implementación Eloquent del repositorio del mapa de traducción sku ↔ material SAP.
 *
 * save() es un upsert por id. La unicidad del sku la respalda el índice único de la tabla.
 */
final class EloquentMaterialMappingRepository implements MaterialMappingRepository
{
    public function __construct(
        private readonly MaterialMappingMapper $mapper,
    ) {}

    public function nextIdentity(): MaterialMappingId
    {
        return MaterialMappingId::generate();
    }

    public function save(MaterialMapping $mapping): void
    {
        MaterialMappingRecord::query()->updateOrCreate(
            ['id' => $mapping->id()->toString()],
            $this->mapper->toRow($mapping),
        );
    }

    public function findBySku(string $sku): ?MaterialMapping
    {
        $record = MaterialMappingRecord::query()->where('sku', trim($sku))->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByMaterial(SapMaterial $material): ?MaterialMapping
    {
        $record = MaterialMappingRecord::query()->where('sap_material', $material->value)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function all(): array
    {
        return MaterialMappingRecord::query()
            ->orderBy('sku')
            ->get()
            ->map(fn (MaterialMappingRecord $record): MaterialMapping => $this->mapper->toDomain($record))
            ->values()
            ->all();
    }
}
