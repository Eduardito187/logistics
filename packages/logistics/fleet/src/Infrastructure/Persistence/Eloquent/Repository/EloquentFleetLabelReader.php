<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Fleet\Domain\Contract\FleetLabelReader;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Implementación Eloquent de las etiquetas legibles de la flota. Cada resolución es UNA sola consulta
 * (`whereIn` + `pluck`), así el enriquecimiento del listado no genera N+1.
 */
final readonly class EloquentFleetLabelReader implements FleetLabelReader
{
    public function platesByVehicleId(array $vehicleIds): array
    {
        if ($vehicleIds === []) {
            return [];
        }

        $plates = [];
        foreach (VehicleModel::query()->whereIn('id', array_unique($vehicleIds))->get(['id', 'plate']) as $vehicle) {
            $plates[$vehicle->id] = $vehicle->plate;
        }

        return $plates;
    }

    public function namesByDriverId(array $driverIds): array
    {
        if ($driverIds === []) {
            return [];
        }

        $names = [];
        foreach (DriverModel::query()->whereIn('id', array_unique($driverIds))->get(['id', 'name']) as $driver) {
            $names[$driver->id] = $driver->name;
        }

        return $names;
    }
}
