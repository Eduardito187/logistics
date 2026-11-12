<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\VehicleRestrictionResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Planning\Presentation\Filament\PlanResource;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource;

/**
 * Listado de restricciones de ruteo por camión, con vuelta a los planes de reparto.
 */
final class ListVehicleRestrictions extends ListRecords
{
    protected static string $resource = VehicleRestrictionResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add truck restrictions')),
            Action::make('backToPlans')
                ->label(__('Delivery Plans'))
                ->icon('heroicon-o-map')
                ->color('gray')
                ->url(PlanResource::getUrl('index')),
        ];
    }
}
