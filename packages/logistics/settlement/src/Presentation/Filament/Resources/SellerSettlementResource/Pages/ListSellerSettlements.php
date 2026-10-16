<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Settlement\Presentation\Filament\Resources\SellerSettlementResource;

/**
 * Listado de liquidaciones de sellers en el admin. El alta se hace con la acción de cabecera "Draft
 * settlement", que despacha el comando (no es un create CRUD).
 */
final class ListSellerSettlements extends ListRecords
{
    protected static string $resource = SellerSettlementResource::class;
}
