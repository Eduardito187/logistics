<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages;

use App\Support\Cockpit\ShipmentHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

/**
 * "Hub 360" de un envío/pedido: cockpit de detalle que agrega la ficha (cliente, dirección, ventana,
 * líneas), el seguimiento en vivo (estado, ETA, posición), la entrega (vehículo + prueba de entrega) y los
 * bultos físicos. Datos cross-context vía {@see ShipmentHub} (tolerante). Usa una vista propia con la capa
 * `.distrack-cockpit` en lugar del infolist CRUD. Conserva el chrome de página Filament.
 */
final class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acciones).
    protected string $view = 'filament.shipments.shipment-hub';

    // Título por el nº de pedido (external_id) o el cliente, no un genérico ni el UUID (consistencia).
    public function getTitle(): string
    {
        $record = $this->getRecord();
        foreach (['external_id', 'customer_name'] as $field) {
            $value = $record->getAttribute($field);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return __('Shipment');
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    /**
     * Datos agregados del hub (cross-context), memoizados durante el request de Livewire.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function hub(): array
    {
        return app(ShipmentHub::class)->overview((string) $this->getRecord()->getKey());
    }
}
