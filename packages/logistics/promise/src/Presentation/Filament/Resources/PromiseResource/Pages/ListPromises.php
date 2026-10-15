<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Filament\Resources\PromiseResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource;

/**
 * Listado de promesas de entrega. No hay alta CRUD: la cabecera expone el flujo transaccional
 * "Quote promise", que cotiza una promesa despachando QuotePromise por el CommandBus.
 */
final class ListPromises extends ListRecords
{
    protected static string $resource = PromiseResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            PromiseResource::quoteAction(),
        ];
    }
}
