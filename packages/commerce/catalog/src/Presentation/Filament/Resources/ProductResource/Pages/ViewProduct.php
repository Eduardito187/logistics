<?php

declare(strict_types=1);

namespace Commerce\Catalog\Presentation\Filament\Resources\ProductResource\Pages;

use Commerce\Catalog\Presentation\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Vista de detalle de un producto en el admin.
 */
final class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;
}
