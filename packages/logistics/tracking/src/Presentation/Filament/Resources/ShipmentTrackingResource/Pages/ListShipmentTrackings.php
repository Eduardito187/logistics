<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource\Pages;

use App\Filament\Concerns\HasSavedViews;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;
use Logistics\Tracking\Presentation\Filament\Resources\ShipmentTrackingResource;

/**
 * Listado del read model de seguimiento.
 *
 * No hay CRUD de creación (la proyección no se edita a mano), pero sí el FLUJO transaccional de abrir
 * el seguimiento de un envío: la acción de cabecera despacha OpenTracking por el CommandBus.
 */
final class ListShipmentTrackings extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = ShipmentTrackingResource::class;

    /**
     * Acción de cabecera: abrir seguimiento (OpenTracking). No es un "New" de CRUD; dispara el caso de
     * uso real por el bus.
     *
     * @return array<int, mixed>
     */
    protected function getHeaderActions(): array
    {
        return [
            ShipmentTrackingResource::openTrackingAction(),
            ...$this->getSavedViewActions(),
        ];
    }

    /**
     * Segmentos por estado visible del seguimiento: en curso (todo lo que avanza hacia la entrega),
     * entregados y fallidos, con conteo. Los conteos salen de la consulta del recurso: respetan el alcance
     * por ciudad del usuario.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $inProgress = [
            VisibleStatus::Pending->value,
            VisibleStatus::Processing->value,
            VisibleStatus::InProgress->value,
            VisibleStatus::Loaded->value,
            VisibleStatus::OnTheWay->value,
            VisibleStatus::Rescheduled->value,
        ];

        return [
            'all' => Tab::make(__('All'))->badge(ShipmentTrackingResource::getEloquentQuery()->count()),
            'in_progress' => Tab::make(__('In progress'))
                ->badge(ShipmentTrackingResource::getEloquentQuery()->whereIn('status', $inProgress)->count())
                ->modifyQueryUsing(static function (Builder $query) use ($inProgress): Builder {
                    /** @var Builder<TrackingRecord> $query */
                    return $query->whereIn('status', $inProgress);
                }),
            'delivered' => $this->statusTab('Delivered', VisibleStatus::Delivered->value),
            'failed' => $this->statusTab('Failed', VisibleStatus::Failed->value),
        ];
    }

    private function statusTab(string $labelKey, string $status): Tab
    {
        return Tab::make(__($labelKey))
            ->badge(ShipmentTrackingResource::getEloquentQuery()->where('status', $status)->count())
            ->modifyQueryUsing(static function (Builder $query) use ($status): Builder {
                /** @var Builder<TrackingRecord> $query */
                return $query->where('status', $status);
            });
    }
}
