<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\DriverResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Presentation\Filament\Resources\DriverResource;

/**
 * Listado de choferes de la flota.
 */
final class ListDrivers extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = DriverResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New driver')),
            ...$this->getSavedViewActions(),
        ];
    }

    /**
     * Segmentos por disponibilidad del chofer (activos / inactivos), con conteo.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->badge(DriverModel::query()->count()),
            'active' => $this->activeTab('Active', true),
            'inactive' => $this->activeTab('Inactive', false),
        ];
    }

    private function activeTab(string $labelKey, bool $active): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(DriverModel::query()->where('active', $active)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($active): Builder {
                /** @var Builder<DriverModel> $query */
                return $query->where('active', $active);
            });
    }
}
