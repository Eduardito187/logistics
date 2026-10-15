<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMapping;
use Logistics\SapConnector\Infrastructure\Persistence\Eloquent\MaterialMappingRecord;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un mapeo de material. No escribe el Record a mano: delega en el caso de uso DefineMaterialMapping
 * vía el CommandBus, que genera la identidad UUID v7 de dominio, valida la coordenada SAP con sus value
 * objects y persiste de forma idempotente por SKU (ADR 002).
 */
final class CreateMaterialMapping extends CreateRecord
{
    protected static string $resource = MaterialMappingResource::class;

    /**
     * Enruta el alta por el comando de dominio en vez de un insert directo de Eloquent.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $sku = (string) $data['sku'];

        app(CommandBus::class)->dispatch(new DefineMaterialMapping(
            $sku,
            (string) $data['sap_material'],
            (string) $data['center'],
            (string) $data['warehouse'],
        ));

        return MaterialMappingRecord::query()->where('sku', $sku)->firstOrFail();
    }
}
