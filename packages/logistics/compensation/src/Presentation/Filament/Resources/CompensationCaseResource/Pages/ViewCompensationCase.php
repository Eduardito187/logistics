<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Filament\Resources\CompensationCaseResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Compensation\Presentation\Filament\Resources\CompensationCaseResource;

/**
 * Vista de detalle de un caso de compensación (traza, montos y productos afectados con nombre + SKU).
 */
final class ViewCompensationCase extends ViewRecord
{
    protected static string $resource = CompensationCaseResource::class;
}
