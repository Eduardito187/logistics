<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Filament\Resources\OrderResource\Pages;

use App\Support\Cockpit\OrderHub;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;

/**
 * "Hub 360" de un pedido comercial (OMS): cockpit de detalle que agrega la ficha del pedido (cliente,
 * dirección de entrega, líneas, total), el estado de pago y la factura asociada. Datos cross-context vía
 * {@see OrderHub} (tolerante). Usa una vista propia con la capa `.distrack-cockpit`. Desde la cabecera se
 * puede cancelar la orden (CancelOrder por el bus CQRS; nunca escribe el record).
 */
final class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acción Cancelar).
    protected string $view = 'filament.orders.order-hub';

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            OrderResource::cancelAction(),
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
        return app(OrderHub::class)->overview((string) $this->getRecord()->getKey());
    }
}
