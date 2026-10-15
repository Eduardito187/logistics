<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\ExpenseResource;

/**
 * Vista de detalle de un gasto de flota en el admin.
 */
final class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;
}
