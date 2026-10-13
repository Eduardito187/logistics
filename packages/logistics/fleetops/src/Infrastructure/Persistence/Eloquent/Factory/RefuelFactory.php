<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Domain\ValueObject\RefuelId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\RefuelRecord;

/**
 * Fábrica de cargas de combustible para seeders de demo y pruebas Feature.
 *
 * @extends Factory<RefuelRecord>
 */
final class RefuelFactory extends Factory
{
    protected $model = RefuelRecord::class;

    public function definition(): array
    {
        $liters = $this->faker->randomFloat(2, 20, 120);
        $pricePerLiterCents = $this->faker->numberBetween(300, 1_200);

        return [
            'id' => RefuelId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'driver_id' => $this->faker->uuid(),
            'fuel_type' => $this->faker->randomElement([
                FuelType::GasolineSpecial->value,
                FuelType::Diesel->value,
                FuelType::Gnv->value,
            ]),
            'liters' => $liters,
            'price_per_liter_cents' => $pricePerLiterCents,
            'currency' => 'BOB',
            'total_cost_cents' => (int) round($liters * $pricePerLiterCents),
            'odometer_km' => $this->faker->numberBetween(10_000, 300_000),
            'station' => $this->faker->company(),
            'full_tank' => $this->faker->boolean(80),
            'refueled_at' => $this->faker->dateTimeBetween('-3 months'),
            'note' => null,
        ];
    }

    /**
     * Carga de un vehículo concreto.
     */
    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    /**
     * Carga a tanque lleno con un odómetro concreto (para probar el consumo entre llenados).
     */
    public function fullTankAt(int $odometerKm): self
    {
        return $this->state(fn (): array => [
            'full_tank' => true,
            'odometer_km' => $odometerKm,
        ]);
    }
}
