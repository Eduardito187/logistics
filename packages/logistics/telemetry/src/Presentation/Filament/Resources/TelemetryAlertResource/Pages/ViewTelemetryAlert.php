<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource;

/**
 * Vista de detalle de una alerta de telemetría en el admin.
 */
final class ViewTelemetryAlert extends ViewRecord
{
    protected static string $resource = TelemetryAlertResource::class;
}
