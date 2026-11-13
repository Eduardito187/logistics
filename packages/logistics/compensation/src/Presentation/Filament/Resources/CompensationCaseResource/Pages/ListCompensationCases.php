<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Filament\Resources\CompensationCaseResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Compensation\Presentation\Filament\Resources\CompensationCaseResource;

/**
 * Listado de casos de compensación (cola de aprobación). Sin alta manual: los casos nacen por la puerta de
 * integraciones (call center) o por los reactores del outbox.
 */
final class ListCompensationCases extends ListRecords
{
    protected static string $resource = CompensationCaseResource::class;
}
