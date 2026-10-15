<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;

/**
 * Alta de un bulto en el admin.
 */
final class CreatePhysicalUnit extends CreateRecord
{
    protected static string $resource = PhysicalUnitResource::class;
}
