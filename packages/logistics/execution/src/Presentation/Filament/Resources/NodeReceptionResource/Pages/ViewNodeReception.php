<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource;

/**
 * Vista de detalle de una recepción en nodo (solo lectura).
 */
final class ViewNodeReception extends ViewRecord
{
    protected static string $resource = NodeReceptionResource::class;
}
