<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Filament\Resources\DeliveryResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Execution\Presentation\Filament\Resources\DeliveryResource;

/**
 * Listado de entregas en ejecución.
 *
 * El encabezado expone el primer flujo del ciclo operativo —el escaneo de preparación— que abre la
 * entrega del pedido. El resto de los escaneos (carga, descarga, recepción en nodo, prueba de entrega
 * y fallo) viven como row actions sobre cada entrega ya abierta. Todos despachan su Command por el
 * CommandBus; nunca se escribe el Record directo.
 */
final class ListDeliveries extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = DeliveryResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeliveryResource::preparationScanAction(),
            ...$this->getSavedViewActions(),
        ];
    }

    /**
     * Segmentos por estado de la entrega (pendiente → en tránsito → entregada / fallida), con conteo.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->badge(DeliveryRecord::query()->count()),
            'pending' => $this->statusTab('Pending', DeliveryStatus::Pending->value),
            'in_transit' => $this->statusTab('In transit', DeliveryStatus::InTransit->value),
            'delivered' => $this->statusTab('Delivered', DeliveryStatus::Delivered->value),
            'failed' => $this->statusTab('Failed', DeliveryStatus::Failed->value),
        ];
    }

    private function statusTab(string $labelKey, string $status): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(DeliveryRecord::query()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<DeliveryRecord> $query */
                return $query->where('status', $status);
            });
    }
}
