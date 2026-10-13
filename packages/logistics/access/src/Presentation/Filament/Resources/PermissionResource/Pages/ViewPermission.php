<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\PermissionResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Access\Presentation\Filament\Resources\PermissionResource;

/**
 * Vista de detalle de un permiso en el admin.
 */
final class ViewPermission extends ViewRecord
{
    protected static string $resource = PermissionResource::class;
}
