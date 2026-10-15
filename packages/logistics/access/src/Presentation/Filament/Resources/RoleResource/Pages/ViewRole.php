<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\RoleResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Access\Presentation\Filament\Resources\RoleResource;

/**
 * Vista de detalle de un rol en el admin.
 */
final class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;
}
