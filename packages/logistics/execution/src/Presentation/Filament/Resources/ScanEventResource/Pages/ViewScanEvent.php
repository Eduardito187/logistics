<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\ScanEventResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource;

/**
 * Vista de detalle de un escaneo de terreno (solo lectura).
 */
final class ViewScanEvent extends ViewRecord
{
    protected static string $resource = ScanEventResource::class;
}
