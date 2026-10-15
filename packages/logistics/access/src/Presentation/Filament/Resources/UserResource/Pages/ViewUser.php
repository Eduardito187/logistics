<?php

declare(strict_types=1);

namespace Logistics\Access\Presentation\Filament\Resources\UserResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Access\Presentation\Filament\Resources\UserResource;

/**
 * Vista de detalle de un usuario en el admin.
 */
final class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;
}
