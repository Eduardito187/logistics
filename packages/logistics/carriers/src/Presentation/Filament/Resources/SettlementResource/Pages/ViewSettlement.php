<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\SettlementResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource;

/**
 * Vista de detalle de una liquidación de transportista en el admin.
 */
final class ViewSettlement extends ViewRecord
{
    protected static string $resource = SettlementResource::class;
}
