<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Filament\Resources\AsnResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource;

/**
 * Listado de ASNs en el admin.
 */
final class ListAsns extends ListRecords
{
    protected static string $resource = AsnResource::class;
}
