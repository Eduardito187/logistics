<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\DriverModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `fleet_drivers`. Produce choferes de demo activos, con número de licencia único
 * y vencimiento de licencia a futuro.
 *
 * @extends Factory<DriverModel>
 */
final class DriverFactory extends Factory
{
    protected $model = DriverModel::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'name' => $this->faker->name(),
            'license_number' => strtoupper($this->faker->bothify('LIC-#####')),
            'license_expiry' => $this->faker->dateTimeBetween('+2 months', '+3 years')->format('Y-m-d'),
            'enabled_vehicle_ids' => [],
            'active' => true,
        ];
    }

    /**
     * Chofer dado de baja (inactivo).
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    /**
     * Chofer habilitado para una lista de vehículos (por sus ids).
     *
     * @param list<string> $vehicleIds
     */
    public function enabledFor(array $vehicleIds): self
    {
        return $this->state(fn (): array => ['enabled_vehicle_ids' => array_values($vehicleIds)]);
    }

    /**
     * Chofer con la licencia ya vencida (para probar las alertas de vencimiento).
     */
    public function withExpiredLicense(): self
    {
        return $this->state(fn (): array => [
            'license_expiry' => $this->faker->dateTimeBetween('-6 months', '-1 day')->format('Y-m-d'),
        ]);
    }
}
