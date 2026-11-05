<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Filament\Resources\OrderResource\Pages;

use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Listado de órdenes del frente comercial en el admin. Solo lectura + acción de cancelación (no hay alta
 * manual de órdenes: nacen del checkout).
 */
final class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
