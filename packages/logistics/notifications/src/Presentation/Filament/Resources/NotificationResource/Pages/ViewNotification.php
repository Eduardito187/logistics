<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Filament\Resources\NotificationResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Notifications\Presentation\Filament\Resources\NotificationResource;

/**
 * Detalle de una notificación del log: cascada, estado e intentos por canal. Solo lectura.
 */
final class ViewNotification extends ViewRecord
{
    protected static string $resource = NotificationResource::class;
}
