<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Filament\Resources\AsnResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Inbound\Presentation\Filament\Resources\AsnResource;

/**
 * Vista de detalle de un ASN en el admin.
 */
final class ViewAsn extends ViewRecord
{
    protected static string $resource = AsnResource::class;
}
