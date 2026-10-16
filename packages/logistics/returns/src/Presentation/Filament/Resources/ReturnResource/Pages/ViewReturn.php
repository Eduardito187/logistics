<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource;

/**
 * Vista de detalle de una devolución en el admin.
 */
final class ViewReturn extends ViewRecord
{
    protected static string $resource = ReturnResource::class;
}
