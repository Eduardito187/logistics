<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource;

/**
 * Vista de detalle de una liquidación de seller en el admin.
 */
final class ViewSellerSettlement extends ViewRecord
{
    protected static string $resource = SellerSettlementResource::class;
}
