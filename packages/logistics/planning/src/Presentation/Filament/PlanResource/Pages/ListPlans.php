<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\PlanResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Logistics\Planning\Presentation\Filament\PlanResource;
use Logistics\Planning\Presentation\Filament\ShipmentRequirementResource;
use Logistics\Planning\Presentation\Filament\VehicleRestrictionResource;

/**
 * Listado de planes de reparto.
 *
 * No hay acción de crear fila: el plan nace por el flujo transaccional "Generate proposal" (comando
 * GeneratePlanProposal), expuesto como acción de cabecera. El resto de flujos (asignar envío, reordenar
 * paradas, publicar) son acciones por fila en la tabla. La cabecera además enlaza a las restricciones de
 * ruteo por camión y por envío (fuera del menú).
 */
final class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            PlanResource::autoPlanAction(),
            PlanResource::generateProposalAction(),
            // Restricciones de ruteo (R-04) que respeta el auto-planificador: por camión y por envío.
            Action::make('truckRestrictions')
                ->label(__('Truck restrictions'))
                ->icon('heroicon-o-no-symbol')
                ->color('gray')
                ->url(VehicleRestrictionResource::getUrl('index')),
            Action::make('shipmentRequirements')
                ->label(__('Shipment requirements'))
                ->icon('heroicon-o-cube')
                ->color('gray')
                ->url(ShipmentRequirementResource::getUrl('index')),
        ];
    }
}
