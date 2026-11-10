<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Filament\Resources\PromiseResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Infrastructure\Persistence\Eloquent\PromiseRecord;
use Logistics\Promise\Presentation\Filament\Resources\PromiseResource;

/**
 * Listado de promesas de entrega. No hay alta CRUD: la cabecera expone el flujo transaccional
 * "Quote promise", que cotiza una promesa despachando QuotePromise por el CommandBus.
 */
final class ListPromises extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = PromiseResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            PromiseResource::quoteAction(),
            ...$this->getSavedViewActions(),
        ];
    }

    /**
     * Segmentos por estado de la promesa (cotizada → reservada → confirmada / vencida), con conteo.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->badge(PromiseRecord::query()->count()),
            'quoted' => $this->statusTab('Quoted', PromiseStatus::Quoted->value),
            'reserved' => $this->statusTab('Reserved', PromiseStatus::Reserved->value),
            'confirmed' => $this->statusTab('Confirmed', PromiseStatus::Confirmed->value),
            'expired' => $this->statusTab('Expired', PromiseStatus::Expired->value),
        ];
    }

    private function statusTab(string $labelKey, string $status): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(PromiseRecord::query()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<PromiseRecord> $query */
                return $query->where('status', $status);
            });
    }
}
