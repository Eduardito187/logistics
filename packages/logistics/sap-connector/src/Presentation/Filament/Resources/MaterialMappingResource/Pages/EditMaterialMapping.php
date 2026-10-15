<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\SapConnector\Application\Command\DefineMaterialMapping\DefineMaterialMapping;
use Logistics\SapConnector\Presentation\Filament\Resources\MaterialMappingResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Edición de un mapeo de material SAP. No actualiza el Record a mano: delega en DefineMaterialMapping vía
 * el CommandBus, idempotente por SKU (conserva la identidad existente y actualiza la coordenada).
 */
final class EditMaterialMapping extends EditRecord
{
    protected static string $resource = MaterialMappingResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Enruta la actualización por el comando de dominio en vez de un update directo de Eloquent.
     *
     * @param array<string, mixed> $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        app(CommandBus::class)->dispatch(new DefineMaterialMapping(
            (string) $data['sku'],
            (string) $data['sap_material'],
            (string) $data['center'],
            (string) $data['warehouse'],
        ));

        return $record->refresh();
    }
}
