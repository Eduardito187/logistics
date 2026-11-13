<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Filament\Resources\ShipmentResource\Pages;

use App\Filament\Concerns\HasEntityNotes;
use App\Support\Cockpit\EntityTimeline;
use App\Support\Cockpit\ShipmentHub;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;
use Logistics\Execution\Presentation\Filament\Resources\NodeReceptionResource;
use Logistics\Execution\Presentation\Filament\Resources\ScanEventResource;
use Logistics\Labels\Application\Query\GetShipmentLabels\GetShipmentLabels;
use Logistics\Labels\Application\ReadModel\EmittedLabelView;
use Logistics\Labels\Presentation\Filament\Resources\EmittedLabelResource;
use Logistics\Planning\Presentation\Filament\PlannedStopResource;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
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

    // Oculta el <h1> de Filament: el hero del cockpit (blade) ya muestra el título; así no se ve duplicado.
    public function getHeading(): string
    {
        return '';
    }

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

    /**
     * Etiquetas emitidas del envío (acotadas por su referencia de pedido), con el estado de impresión, para
     * la pestaña "Seguimiento". Se resuelven por el QueryBus (CQRS), nunca leyendo el Record directo. Las
     * etiquetas son permanentes: se listan tal como se emitieron. Tolerante: lista vacía si no hay etiquetas
     * o la fuente no está disponible.
     *
     * @return list<array{label_id: string, sku: string, product_name: string | null, qr_content: string, destination_name: string | null, unit_index: int, unit_total: int, piece_index: int, piece_total: int, printed: bool}>
     */
    #[Computed]
    public function labels(): array
    {
        $orderRef = $this->orderReference();
        if ($orderRef === '') {
            return [];
        }

        try {
            /** @var list<EmittedLabelView> $views */
            $views = app(QueryBus::class)->ask(new GetShipmentLabels($orderRef));
        } catch (\Throwable) {
            return [];
        }

        if ($views === []) {
            return [];
        }

        $printed = $this->printedLabelIds();

        $out = [];
        foreach ($views as $view) {
            $out[] = [
                'label_id' => $view->labelId,
                'sku' => $view->sku,
                'product_name' => $view->productName,
                'qr_content' => $view->qrContent,
                'destination_name' => $view->destinationName,
                'unit_index' => $view->unitIndex,
                'unit_total' => $view->unitTotal,
                'piece_index' => $view->pieceIndex,
                'piece_total' => $view->pieceTotal,
                'printed' => in_array($view->labelId, $printed, true),
            ];
        }

        return $out;
    }

    /**
     * Hitos del ciclo de vida del envío (asignado, preparado, cargado, entregado) tomados del read model de
     * avance operativo ({@see ShipmentProgressReader}). Tolerante: campo null si el hito aún no ocurrió o la
     * fuente no está disponible; así la pestaña degrada a vacío sin romper la página.
     *
     * @return array{assigned_at: string | null, prepared_at: string | null, loaded_at: string | null, delivered_at: string | null, execution_status: string | null}
     */
    #[Computed]
    public function progress(): array
    {
        $id = (string) $this->getRecord()->getKey();

        try {
            $view = app(ShipmentProgressReader::class)->forShipmentIds([$id])[$id] ?? null;
        } catch (\Throwable) {
            $view = null;
        }

        return [
            'assigned_at' => $view?->assignedAt,
            'prepared_at' => $view?->preparedAt,
            'loaded_at' => $view?->loadedAt,
            'delivered_at' => $view?->deliveredAt,
            'execution_status' => $view?->executionStatus,
        ];
    }

    /** Referencia de pedido del envío (order_reference, o external_id en filas viejas) para sus etiquetas. */
    private function orderReference(): string
    {
        $record = $this->getRecord();
        foreach (['order_reference', 'external_id'] as $field) {
            $value = $record->getAttribute($field);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * label_ids que YA tienen un evento de impresión en la bitácora de ciclo de vida (para el badge
     * «impresa/pendiente» de cada etiqueta). Tolerante: lista vacía si la tabla no existe todavía.
     *
     * @return list<string>
     */
    private function printedLabelIds(): array
    {
        try {
            return DB::table('label_events')
                ->where('event', 'printed')
                ->distinct()
                ->pluck('label_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
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
