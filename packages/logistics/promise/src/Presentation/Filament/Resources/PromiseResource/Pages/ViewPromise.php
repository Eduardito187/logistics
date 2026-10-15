<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Filament\Resources\PromiseResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource;

/**
 * Detalle de una promesa de entrega. La cabecera expone los flujos transaccionales que aplican según
 * el estado del registro (reservar / confirmar / expirar), cada uno despachando su Command por el
 * CommandBus. No hay edición CRUD.
 */
final class ViewPromise extends ViewRecord
{
    protected static string $resource = PromiseResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            PromiseResource::reserveAction(),
            PromiseResource::confirmAction(),
            PromiseResource::expireAction(),
        ];
    }
}
