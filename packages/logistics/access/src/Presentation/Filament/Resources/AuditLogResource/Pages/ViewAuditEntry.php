<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\AuditLogResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Access\Presentation\Filament\Resources\AuditLogResource;

/**
 * Vista de detalle de una entrada de auditoría en el admin.
 */
final class ViewAuditEntry extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;
}
