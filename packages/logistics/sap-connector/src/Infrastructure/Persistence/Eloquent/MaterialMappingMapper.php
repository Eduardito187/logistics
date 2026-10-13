<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;

/**
 * Traduce entre la entidad MaterialMapping y su fila Eloquent. Toda la conversión a/desde primitivos
 * vive acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class MaterialMappingMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(MaterialMapping $mapping): array
    {
        return [
            'id' => $mapping->id()->toString(),
            'sku' => $mapping->sku(),
            'sap_material' => $mapping->material()->value,
            'center' => $mapping->center()->value,
            'warehouse' => $mapping->warehouse()->value,
        ];
    }

    public function toDomain(MaterialMappingRecord $record): MaterialMapping
    {
        return MaterialMapping::reconstitute(
            MaterialMappingId::fromString($record->id),
            $record->sku,
            SapMaterial::of($record->sap_material),
            SapCenter::of($record->center),
            SapWarehouse::of($record->warehouse),
        );
    }
}
