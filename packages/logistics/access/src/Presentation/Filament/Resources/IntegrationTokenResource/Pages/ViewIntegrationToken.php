<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Access\Presentation\Filament\Resources\IntegrationTokenResource;

/**
 * Vista de detalle de un token de integración en el admin.
 */
final class ViewIntegrationToken extends ViewRecord
{
    protected static string $resource = IntegrationTokenResource::class;
}
