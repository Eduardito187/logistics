<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenanceOrderResource;

/**
 * Listado de órdenes de mantenimiento en el admin.
 */
final class ListMaintenanceOrders extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = MaintenanceOrderResource::class;

    /**
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Schedule maintenance')),
            ...$this->getSavedViewActions(),
        ];
    }

    /**
     * Segmentos por estado de la orden de mantenimiento (agendada → en progreso → completada / cancelada).
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All'))->badge(MaintenanceOrderRecord::query()->count()),
            'scheduled' => $this->statusTab('Scheduled', MaintenanceStatus::Scheduled->value),
            'in_progress' => $this->statusTab('In progress', MaintenanceStatus::InProgress->value),
            'completed' => $this->statusTab('Completed', MaintenanceStatus::Completed->value),
            'cancelled' => $this->statusTab('Cancelled', MaintenanceStatus::Cancelled->value),
        ];
    }

    private function statusTab(string $labelKey, string $status): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(MaintenanceOrderRecord::query()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<MaintenanceOrderRecord> $query */
                return $query->where('status', $status);
            });
    }
}
