<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource;

/**
 * Listado de devoluciones en el admin.
 */
final class ListReturns extends ListRecords
{
    protected static string $resource = ReturnResource::class;
}
