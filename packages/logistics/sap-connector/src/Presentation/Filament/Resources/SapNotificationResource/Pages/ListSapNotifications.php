<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Filament\Resources\SapNotificationResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\SapConnector\Presentation\Filament\Resources\SapNotificationResource;

/**
 * Listado de avisos a SAP. Solo lectura: sin acción de creación en el encabezado.
 */
final class ListSapNotifications extends ListRecords
{
    protected static string $resource = SapNotificationResource::class;
}
