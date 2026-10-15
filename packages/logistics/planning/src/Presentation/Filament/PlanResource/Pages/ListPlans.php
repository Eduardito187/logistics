<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Filament\PlanResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Logistics\Planning\Presentation\Filament\PlanResource;

/**
 * Listado de planes de reparto.
 *
 * No hay acción de crear fila: el plan nace por el flujo transaccional "Generate proposal" (comando
 * GeneratePlanProposal), expuesto como acción de cabecera. El resto de flujos (asignar envío, reordenar
 * paradas, publicar) son acciones por fila en la tabla.
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
        ];
    }
}
