<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Filament\Resources\VoucherResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Logistics\Compensation\Presentation\Filament\Resources\VoucherResource;

/**
 * Listado de vales emitidos. Solo lectura sobre la fila: el código se muestra enmascarado y las acciones
 * (anular / reenviar email) despachan al núcleo.
 */
final class ListVouchers extends ListRecords
{
    protected static string $resource = VoucherResource::class;
}
