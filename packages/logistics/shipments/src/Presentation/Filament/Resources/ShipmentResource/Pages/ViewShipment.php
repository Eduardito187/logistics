<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\EntityTimeline;
use App\Support\Cockpit\ShipmentHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\Computed;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource;
use Logistics\Planning\Presentation\Filament\PlannedStopResource;
use Logistics\Shipments\Presentation\Filament\Resources\PhysicalUnitResource;
use Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource;

/**
 * "Hub 360" de un envío/pedido: cockpit de detalle que agrega la ficha (cliente, dirección, ventana,
 * líneas), el seguimiento en vivo (estado, ETA, posición), la entrega (vehículo + prueba de entrega) y los
 * bultos físicos. Datos cross-context vía {@see ShipmentHub} (tolerante). Usa una vista propia con la capa
 * `.distrack-cockpit` en lugar del infolist CRUD. Conserva el chrome de página Filament.
 */
final class ViewShipment extends ViewRecord
{
    use HasEntityNotes;

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

    /**
     * Línea de tiempo (actividad) del envío: por su id y su referencia de pedido (external_id), para
     * capturar también los eventos de etiquetas keyeados por la orden.
     *
     * @return list<array{at: string, when: string, label: string, kind: string, actor: ?string}>
     */
    #[Computed]
    public function timeline(): array
    {
        $record = $this->getRecord();
        $external = $record->getAttribute('external_id');

        return app(EntityTimeline::class)->forIds([
            (string) $record->getKey(),
            is_string($external) ? $external : '',
        ]);
    }

    /** Tipo lógico de la entidad para las notas/seguimientos del hub. */
    protected function noteSubjectType(): string
    {
        return 'shipment';
    }

    /**
     * Enlaces de «Gestión»: registros que dependen de este pedido/envío (etiquetas emitidas) que ya no
     * viven en el menú; su hogar es esta ficha. Tolerante.
     *
     * @return list<array{label: string, url: string}>
     */
    public function managementLinks(): array
    {
        $record = $this->getRecord();
        $id = (string) $record->getKey();
        // Las etiquetas se emiten por PEDIDO (order_id): se acotan por el `order_reference` del envío.
        $orderRef = $record->getAttribute('order_reference');

        $out = [];
        try {
            $url = EmittedLabelResource::getUrl('index');
            if (is_string($orderRef) && $orderRef !== '') {
                $url .= '?order='.rawurlencode($orderRef);
            }
            $out[] = ['label' => 'Etiquetas emitidas', 'url' => $url];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }

        // Paquetes (unidades físicas) del envío: lista acotada por `?shipment=<id>`.
        try {
            $out[] = ['label' => 'Paquetes', 'url' => PhysicalUnitResource::getUrl('index').'?shipment='.rawurlencode($id)];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }
        // Entregas (última milla) de este envío: lista acotada por `?shipment=<id>`.
        try {
            $out[] = ['label' => 'Entregas', 'url' => DeliveryResource::getUrl('index').'?shipment='.rawurlencode($id)];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }
        // Recepciones en nodo de este envío: acotadas por `?shipment=<id>`.
        try {
            $out[] = ['label' => 'Recepciones en nodo', 'url' => NodeReceptionResource::getUrl('index').'?shipment='.rawurlencode($id)];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }
        // Escaneos en terreno de este envío (vía su entrega): acotados por `?shipment=<id>`.
        try {
            $out[] = ['label' => 'Escaneos', 'url' => ScanEventResource::getUrl('index').'?shipment='.rawurlencode($id)];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }
        // Paradas planificadas de este envío: acotadas por `?shipment=<id>`.
        try {
            $out[] = ['label' => 'Paradas planificadas', 'url' => PlannedStopResource::getUrl('index').'?shipment='.rawurlencode($id)];
        } catch (\Throwable) {
            // recurso no disponible: se omite.
        }

        return $out;
    }
}
