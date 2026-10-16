<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Filament\Resources\SellerResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Sellers\Presentation\Filament\Resources\SellerResource;

/**
 * Vista de detalle de un seller en el admin.
 */
final class ViewSeller extends ViewRecord
{
    protected static string $resource = SellerResource::class;
}
