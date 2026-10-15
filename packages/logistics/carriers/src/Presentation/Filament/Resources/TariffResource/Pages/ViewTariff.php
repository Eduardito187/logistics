<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\TariffResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Carriers\Presentation\Filament\Resources\TariffResource;

/**
 * Vista de detalle de una tarifa por corredor en el admin (solo lectura).
 */
final class ViewTariff extends ViewRecord
{
    protected static string $resource = TariffResource::class;
}
