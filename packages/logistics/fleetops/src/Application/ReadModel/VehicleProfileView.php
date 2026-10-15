<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use Logistics\FleetOps\Domain\Model\VehicleProfile;

/**
 * Proyección de lectura del perfil técnico de un vehículo para listados y API. Primitivos: no expone
 * objetos de dominio.
 */
final readonly class VehicleProfileView
{
    private function __construct(
        public string $id,
        public string $vehicleId,
        public ?string $brand,
        public ?string $model,
        public ?int $year,
        public ?string $fuelType,
        public ?float $tankLiters,
        public ?string $vin,
        public ?string $color,
        public ?int $currentOdometerKm,
    ) {}

    public static function fromDomain(VehicleProfile $profile): self
    {
        return new self(
            $profile->id()->toString(),
            $profile->vehicleId(),
            $profile->brand(),
            $profile->model(),
            $profile->year(),
            $profile->fuelType()?->value,
            $profile->tankLiters(),
            $profile->vin(),
            $profile->color(),
            $profile->currentOdometer()?->km,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, brand: string|null, model: string|null, year: int|null, fuel_type: string|null, tank_liters: float|null, vin: string|null, color: string|null, current_odometer_km: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'fuel_type' => $this->fuelType,
            'tank_liters' => $this->tankLiters,
            'vin' => $this->vin,
            'color' => $this->color,
            'current_odometer_km' => $this->currentOdometerKm,
        ];
    }
}
