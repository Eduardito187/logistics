<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Outbox\Presentation\Filament\Resources\OutboxMessageResource;

/**
 * Detalle de un mensaje del outbox: estado, reintentos, último error y payload completo. Solo lectura.
 */
final class ViewOutboxMessage extends ViewRecord
{
    protected static string $resource = OutboxMessageResource::class;
}
