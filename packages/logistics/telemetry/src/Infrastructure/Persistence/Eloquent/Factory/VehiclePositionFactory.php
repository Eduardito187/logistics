<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\VehiclePositionRecord;

/**
 * Fábrica de la posición en vivo de un vehículo para seeders de demo y pruebas Feature.
 *
 * La PK es el vehicle_id (string, del contexto fleet): se genera como UUID opaco. No pasa por el dominio a
 * propósito: una fábrica arma filas directamente.
 *
 * @extends Factory<VehiclePositionRecord>
 */
final class VehiclePositionFactory extends Factory
{
    protected $model = VehiclePositionRecord::class;

    public function definition(): array
    {
        $moving = $this->faker->boolean();

        return [
            'vehicle_id' => $this->faker->uuid(),
            'lat' => $this->faker->randomFloat(7, -17.80, -17.30),
            'lng' => $this->faker->randomFloat(7, -66.25, -66.10),
            'speed_kph' => $moving ? $this->faker->randomFloat(2, 3, 90) : 0.0,
            'heading' => $this->faker->numberBetween(0, 359),
            'ignition' => $moving,
            'moving' => $moving,
            'route_id' => null,
            'recorded_at' => $this->faker->dateTimeThisMonth(),
        ];
    }

    /**
     * Posición detenida: sin movimiento ni velocidad.
     */
    public function stopped(): self
    {
        return $this->state(fn (): array => [
            'speed_kph' => 0.0,
            'ignition' => false,
            'moving' => false,
        ]);
    }
}
