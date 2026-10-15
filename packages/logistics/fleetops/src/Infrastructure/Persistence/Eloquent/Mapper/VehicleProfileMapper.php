<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\VehicleProfileId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;

/**
 * Traduce entre el agregado VehicleProfile (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class VehicleProfileMapper
{
    public function toDomain(VehicleProfileRecord $record): VehicleProfile
    {
        return VehicleProfile::reconstitute(
            VehicleProfileId::fromString($record->id),
            $record->vehicle_id,
            $record->brand,
            $record->model,
            $record->year,
            $record->fuel_type !== null ? FuelType::from($record->fuel_type) : null,
            $record->tank_liters !== null ? (float) $record->tank_liters : null,
            $record->vin,
            $record->color,
            $record->current_odometer_km !== null ? Odometer::km($record->current_odometer_km) : null,
        );
    }

    /**
     * @return array{vehicle_id: string, id: string, brand: string|null, model: string|null, year: int|null, fuel_type: string|null, tank_liters: float|null, vin: string|null, color: string|null, current_odometer_km: int|null}
     */
    public function toRow(VehicleProfile $profile): array
    {
        return [
            'vehicle_id' => $profile->vehicleId(),
            'id' => $profile->id()->toString(),
            'brand' => $profile->brand(),
            'model' => $profile->model(),
            'year' => $profile->year(),
            'fuel_type' => $profile->fuelType()?->value,
            'tank_liters' => $profile->tankLiters(),
            'vin' => $profile->vin(),
            'color' => $profile->color(),
            'current_odometer_km' => $profile->currentOdometer()?->km,
        ];
    }
}
