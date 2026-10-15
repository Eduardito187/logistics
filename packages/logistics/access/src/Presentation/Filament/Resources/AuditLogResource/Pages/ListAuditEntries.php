<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\AuditLogResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Access\Presentation\Filament\Resources\AuditLogResource;

/**
 * Listado de la bitácora de auditoría en el admin. Solo lectura: sin acción de alta en la cabecera.
 */
final class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditLogResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
