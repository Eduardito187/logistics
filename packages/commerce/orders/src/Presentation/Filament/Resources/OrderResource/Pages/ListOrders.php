<?php

declare(strict_types=1);

namespace Commerce\Orders\Presentation\Filament\Resources\OrderResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Commerce\Orders\Presentation\Filament\Resources\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Listado de órdenes del frente comercial en el admin. Solo lectura + acción de cancelación (no hay alta
 * manual de órdenes: nacen del checkout).
 *
 * Los SEGMENTOS por estado del ciclo de vida (pendiente de pago → pagado → en preparación → cancelado)
 * hacen que la lista se use como un CRM —"dónde está cada pedido"— en vez de una tabla cruda.
 */
final class ListOrders extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = OrderResource::class;

    /**
     * Acciones de cabecera: vistas guardadas por usuario (no hay alta manual de órdenes).
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return $this->getSavedViewActions();
    }

    /**
     * Segmentos por estado de la orden, con el conteo por segmento.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->badge(OrderRecord::query()->count()),
            'pending' => $this->statusTab('Pending', OrderStatus::Pending->value),
            'paid' => $this->statusTab('Paid', OrderStatus::Paid->value),
            'fulfilling' => $this->statusTab('Fulfilling', OrderStatus::Fulfilling->value),
            'cancelled' => $this->statusTab('Cancelled', OrderStatus::Cancelled->value),
        ];
    }

    /**
     * Pestaña de un estado: su etiqueta traducida, el conteo y el filtro de la consulta.
     */
    private function statusTab(string $labelKey, string $status): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(OrderRecord::query()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<OrderRecord> $query */
                return $query->where('status', $status);
            });
    }
}
