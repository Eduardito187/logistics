<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Logistics\FleetOps\Application\Command\DefineMaintenancePlan\DefineMaintenancePlan;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;
use Logistics\FleetOps\Presentation\Filament\Resources\MaintenancePlanResource;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Alta de un plan de mantenimiento preventivo desde el admin.
 *
 * No persiste con Eloquent: despacha DefineMaintenancePlan al bus CQRS (el dominio genera el id). Como el
 * comando no devuelve el id, se recupera el plan recién creado (vehículo + servicio, el más reciente por
 * UUID v7) para la redirección del admin.
 */
final class CreateMaintenancePlan extends CreateRecord
{
    protected static string $resource = MaintenancePlanResource::class;

    /**
     * @param array<string, mixed> $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $vehicleId = (string) $data['vehicle_id'];
        $serviceKind = (string) $data['service_kind'];

        app(CommandBus::class)->dispatch(new DefineMaintenancePlan(
            vehicleId: $vehicleId,
            serviceKind: $serviceKind,
            intervalKm: isset($data['interval_km']) && is_numeric($data['interval_km']) ? (int) $data['interval_km'] : null,
            intervalDays: isset($data['interval_days']) && is_numeric($data['interval_days']) ? (int) $data['interval_days'] : null,
        ));

        // UUID v7 es ordenable en el tiempo: el id más alto del vehículo/servicio es el plan recién creado.
        return MaintenancePlanRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->where('service_kind', $serviceKind)
            ->orderByDesc('id')
            ->firstOrFail();
    }
}
