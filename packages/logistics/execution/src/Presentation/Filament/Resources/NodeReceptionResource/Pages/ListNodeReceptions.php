<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource;

/**
 * Listado de recepciones en nodo (solo lectura).
 */
final class ListNodeReceptions extends ListRecords
{
    protected static string $resource = NodeReceptionResource::class;
}
