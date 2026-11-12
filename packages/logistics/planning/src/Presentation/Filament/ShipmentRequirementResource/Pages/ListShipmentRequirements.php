<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\ShipmentRequirementResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Logistics\Planning\Presentation\Filament\PlanResource;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource;

/**
 * Listado de requisitos de ruteo por envío, con vuelta a los planes de reparto.
 */
final class ListShipmentRequirements extends ListRecords
{
    protected static string $resource = ShipmentRequirementResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('Add shipment requirement')),
            Action::make('backToPlans')
                ->label(__('Delivery Plans'))
                ->icon('heroicon-o-map')
                ->color('gray')
                ->url(PlanResource::getUrl('index')),
        ];
    }
}
