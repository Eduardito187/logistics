<?php

declare(strict_types=1);

namespace Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Inventory\Presentation\Filament\Resources\StockOnHandResource;

/**
 * Vista de detalle de un lote de stock en el admin.
 */
final class ViewStockLot extends ViewRecord
{
    protected static string $resource = StockOnHandResource::class;
}
