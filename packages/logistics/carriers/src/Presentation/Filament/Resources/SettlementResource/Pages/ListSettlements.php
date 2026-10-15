<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\SettlementResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Carriers\Presentation\Filament\Resources\SettlementResource;

/**
 * Listado de liquidaciones de transportistas en el admin.
 *
 * No hay acción de alta: las liquidaciones nacen por la puerta de integraciones (el transportista las
 * envía); desde el admin solo se revisan, aprueban, pagan u objetan.
 */
final class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;
}
