<?php

declare(strict_types=1);

namespace Logistics\Fleet\Presentation\Filament\Resources\VehicleResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Fleet\Presentation\Filament\Resources\VehicleResource;

/**
 * Listado de vehículos de la flota.
 */
final class ListVehicles extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = VehicleResource::class;

    /**
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New vehicle')),
            ...$this->getSavedViewActions(),
        ];
    }

    /**
     * Segmentos por estado del vehículo (disponible / en servicio / mantenimiento / inactivo), con conteo.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->badge(VehicleModel::query()->count()),
            'available' => $this->statusTab('Available', VehicleStatus::Available->value),
            'in_service' => $this->statusTab('In service', VehicleStatus::InService->value),
            'maintenance' => $this->statusTab('Maintenance', VehicleStatus::Maintenance->value),
            'inactive' => $this->statusTab('Inactive', VehicleStatus::Inactive->value),
        ];
    }

    private function statusTab(string $labelKey, string $status): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(VehicleModel::query()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<VehicleModel> $query */
                return $query->where('status', $status);
            });
    }
}
