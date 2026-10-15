<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\DepartureRecord;

/**
 * Fábrica de salidas para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica arma filas directamente.
 *
 * @extends Factory<DepartureRecord>
 */
final class DepartureFactory extends Factory
{
    protected $model = DepartureRecord::class;

    public function definition(): array
    {
        $odometerOut = $this->faker->numberBetween(0, 500_000);

        return [
            'id' => DepartureId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'driver_id' => $this->faker->uuid(),
            'helper_ids' => [],
            'destination' => $this->faker->city(),
            'route_ref' => null,
            'load_description' => $this->faker->words(3, true),
            'load_weight_kg' => $this->faker->randomFloat(2, 0, 3000),
            'load_items' => [],
            'odometer_out_km' => $odometerOut,
            'odometer_in_km' => null,
            'status' => DepartureStatus::Out->value,
            'departed_at' => $this->faker->dateTimeThisMonth(),
            'returned_at' => null,
            'note' => null,
        ];
    }

    /**
     * Salida ya retornada (cerrada): odómetro de regreso y fecha de retorno.
     */
    public function returned(int $kmTraveled = 120): self
    {
        return $this->state(function (array $attributes) use ($kmTraveled): array {
            $out = is_int($attributes['odometer_out_km'] ?? null) ? $attributes['odometer_out_km'] : 0;
            $departedAt = $attributes['departed_at'] ?? new DateTimeImmutable;

            return [
                'status' => DepartureStatus::Returned->value,
                'odometer_in_km' => $out + $kmTraveled,
                'returned_at' => $departedAt,
            ];
        });
    }

    /**
     * Salida de un vehículo concreto.
     */
    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }
}
