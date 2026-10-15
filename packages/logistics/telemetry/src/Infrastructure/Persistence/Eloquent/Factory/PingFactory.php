<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;

/**
 * Fábrica de pings (lecturas GPS) para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica arma filas directamente.
 *
 * @extends Factory<PingRecord>
 */
final class PingFactory extends Factory
{
    protected $model = PingRecord::class;

    public function definition(): array
    {
        $recordedAt = $this->faker->dateTimeThisMonth();

        return [
            'id' => PingId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'driver_id' => null,
            'device_id' => null,
            'route_id' => null,
            'lat' => $this->faker->randomFloat(7, -17.80, -17.30),
            'lng' => $this->faker->randomFloat(7, -66.25, -66.10),
            'speed_kph' => $this->faker->randomFloat(2, 0, 80),
            'heading' => $this->faker->numberBetween(0, 359),
            'accuracy_m' => $this->faker->randomFloat(2, 1, 20),
            'ignition' => true,
            'odometer_m' => $this->faker->numberBetween(0, 500_000_000),
            'recorded_at' => $recordedAt,
            'received_at' => $recordedAt,
            'source' => $this->faker->randomElement(array_map(
                static fn (PingSource $source): string => $source->value,
                PingSource::cases(),
            )),
            'trip_id' => null,
        ];
    }

    /**
     * Ping detenido (sin movimiento): velocidad nula y motor apagado.
     */
    public function stopped(): self
    {
        return $this->state(fn (): array => [
            'speed_kph' => 0.0,
            'ignition' => false,
        ]);
    }

    /**
     * Ping de un vehículo concreto en un instante dado.
     */
    public function forVehicleAt(string $vehicleId, DateTimeImmutable $recordedAt): self
    {
        return $this->state(fn (): array => [
            'vehicle_id' => $vehicleId,
            'recorded_at' => $recordedAt,
            'received_at' => $recordedAt,
        ]);
    }
}
