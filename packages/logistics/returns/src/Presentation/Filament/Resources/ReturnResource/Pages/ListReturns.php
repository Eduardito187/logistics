<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Filament\Resources\ReturnResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;
use Logistics\Returns\Presentation\Filament\Resources\ReturnResource;

/**
 * Listado de devoluciones con SEGMENTOS por estado del ciclo (badges de conteo): Todas → Iniciada →
 * Recibida → Dispuesta → Cerrada. Las etiquetas salen de {@see ReturnResource::statusOptions()} (misma
 * fuente que la columna de estado). La fila abre la ficha 360 (hub) de la devolución.
 */
final class ListReturns extends ListRecords
{
    protected static string $resource = ReturnResource::class;

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('All'))->badge(ReturnRecord::query()->count())];

        foreach (ReturnResource::statusOptions() as $status => $label) {
            $tabs[$status] = $this->statusTab($label, $status);
        }

        return $tabs;
    }

    private function statusTab(string $label, string $status): Tab
    {
        return Tab::make($label)
            ->badge(ReturnRecord::query()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<ReturnRecord> $query */
                return $query->where('status', $status);
            });
    }
}
