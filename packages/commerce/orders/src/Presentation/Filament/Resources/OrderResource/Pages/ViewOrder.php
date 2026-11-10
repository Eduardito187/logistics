<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Filament\Resources\OrderResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\EntityTimeline;
use App\Support\Cockpit\OrderHub;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource;

/**
 * "Hub 360" de un pedido comercial (OMS): cockpit de detalle que agrega la ficha del pedido (cliente,
 * dirección de entrega, líneas, total), el estado de pago y la factura asociada. Datos cross-context vía
 * {@see OrderHub} (tolerante). Usa una vista propia con la capa `.distrack-cockpit`. Desde la cabecera se
 * puede cancelar la orden (CancelOrder por el bus CQRS; nunca escribe el record).
 */
final class ViewOrder extends ViewRecord
{
    use HasEntityNotes;

    protected static string $resource = OrderResource::class;

    // Vista propia (cockpit); conserva el chrome de página Filament (cabecera + acción Cancelar).
    protected string $view = 'filament.orders.order-hub';

    // Título por el cliente (instantánea de ship_to), no un genérico ni el UUID (consistencia con los hubs).
    public function getTitle(): string
    {
        $shipTo = $this->getRecord()->getAttribute('ship_to');
        $name = is_array($shipTo) && isset($shipTo['customer_name']) && is_string($shipTo['customer_name'])
            ? $shipTo['customer_name']
            : '';

        return $name !== '' ? $name : __('Order');
    }

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

    /**
     * Línea de tiempo (actividad) de la entidad, memoizada durante el request.
     *
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    #[Computed]
    public function timeline(): array
    {
        return app(EntityTimeline::class)->forIds([(string) $this->getRecord()->getKey()]);
    }

    /** Tipo lógico de la entidad para las notas/seguimientos del hub. */
    protected function noteSubjectType(): string
    {
        return 'order';
    }

    /**
     * Enlaces de «Gestión»: registros que dependen de este pedido/envío (etiquetas emitidas) que ya no
     * viven en el menú; su hogar es esta ficha. Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $out = [];
        try {
            // Etiquetas de este pedido: lista acotada por `?order=<id>` (EmittedLabel filtra por order_id).
            $order = '?order='.rawurlencode((string) $this->getRecord()->getKey());
            $out[] = ['label' => 'Etiquetas emitidas', 'url' => EmittedLabelResource::getUrl('index').$order];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }
        try {
            // Promesas: su referencia (shipment_ref) es una clave suelta que no se empareja limpio con el id
            // del pedido en los datos actuales, así que se enlaza a la lista completa (su hogar es esta ficha).
            $out[] = ['label' => 'Promesas de entrega', 'url' => PromiseResource::getUrl('index')];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }

        return $out;
    }
}
