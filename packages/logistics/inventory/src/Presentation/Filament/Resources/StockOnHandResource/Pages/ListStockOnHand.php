<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource;

/**
 * Listado de stock on-hand por lote en el admin. Sin acción de alta: el stock entra por los comandos de
 * dominio / la recepción de inbound, no por CRUD.
 */
final class ListStockOnHand extends ListRecords
{
    protected static string $resource = StockOnHandResource::class;
}
