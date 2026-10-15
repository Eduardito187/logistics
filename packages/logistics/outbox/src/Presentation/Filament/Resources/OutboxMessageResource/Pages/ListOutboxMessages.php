<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource;

/**
 * Listado del outbox (pendientes, fallidos, despachados y dead-letter). Solo lectura: sin acción de
 * creación en el encabezado.
 */
final class ListOutboxMessages extends ListRecords
{
    protected static string $resource = OutboxMessageResource::class;
}
