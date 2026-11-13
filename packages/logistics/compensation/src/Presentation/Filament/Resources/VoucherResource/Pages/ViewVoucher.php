<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Filament\Resources\VoucherResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Logistics\Compensation\Presentation\Filament\Resources\VoucherResource;

/**
 * Vista de detalle de un vale (código enmascarado, estado, vencimiento y datos de canje).
 */
final class ViewVoucher extends ViewRecord
{
    protected static string $resource = VoucherResource::class;
}
