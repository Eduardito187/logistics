<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Telemetry\Presentation\Filament\Resources\TelemetryAlertResource;

/**
 * Listado de alertas de telemetría en el admin.
 */
final class ListTelemetryAlerts extends ListRecords
{
    protected static string $resource = TelemetryAlertResource::class;
}
