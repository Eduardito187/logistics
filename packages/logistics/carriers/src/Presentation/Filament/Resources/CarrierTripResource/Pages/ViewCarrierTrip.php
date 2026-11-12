<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Logistics\Carriers\Presentation\Filament\Resources\CarrierTripResource;

/**
 * Ficha de un viaje: datos del corredor, estado y carga (envíos → productos con nombre + SKU). Desde acá
 * la oficina arma la carga y opera el viaje (siempre por comandos).
 */
final class ViewCarrierTrip extends ViewRecord
{
    protected static string $resource = CarrierTripResource::class;

    // Título legible (transportista · corredor · fecha), nunca el UUID.
    public function getTitle(): string
    {
        return CarrierTripResource::getRecordTitle($this->getRecord()) ?? __('Carrier trip');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CarrierTripResource::addShipmentsAction(),
            CarrierTripResource::removeShipmentAction(),
            CarrierTripResource::startAction(),
            CarrierTripResource::closeAction(),
            CarrierTripResource::cancelAction(),
        ];
    }
}
