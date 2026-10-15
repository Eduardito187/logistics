<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\ScanEventResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource;

/**
 * Listado de escaneos de terreno (solo lectura, append-only).
 */
final class ListScanEvents extends ListRecords
{
    protected static string $resource = ScanEventResource::class;
}
