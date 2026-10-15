<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\VehicleProfileId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\VehicleProfileRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de perfiles técnicos de vehículos para seeders de demo y pruebas Feature.
 *
 * @extends Factory<VehicleProfileRecord>
 */
final class VehicleProfileFactory extends Factory
{
    protected $model = VehicleProfileRecord::class;

    public function definition(): array
    {
        return [
            'vehicle_id' => Uuid::generate()->value,
            'id' => VehicleProfileId::generate()->toString(),
            'brand' => $this->faker->randomElement(['Volvo', 'Scania', 'Isuzu', 'Toyota']),
            'model' => $this->faker->bothify('Model-##'),
            'year' => $this->faker->numberBetween(2015, 2026),
            'fuel_type' => $this->faker->randomElement(FuelType::cases())->value,
            'tank_liters' => $this->faker->numberBetween(60, 400),
            'vin' => strtoupper($this->faker->bothify('?#?#?#?#?#?#?#?#?')),
            'color' => $this->faker->safeColorName(),
            'current_odometer_km' => $this->faker->numberBetween(0, 300_000),
        ];
    }

    /**
     * Perfil de un vehículo concreto (por su id de fleet).
     */
    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    /**
     * Perfil con un odómetro vigente concreto en kilómetros.
     */
    public function withOdometer(int $km): self
    {
        return $this->state(fn (): array => ['current_odometer_km' => $km]);
    }
}
