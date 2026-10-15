<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\OdometerReadingRecord;

/**
 * Fábrica de lecturas de odómetro para seeders de demo y pruebas Feature.
 *
 * Genera el id como UUID v7 real (vía el id tipado del dominio) para que coincida con lo que produce el
 * repositorio en producción. No pasa por el dominio a propósito: una fábrica arma filas directamente.
 *
 * @extends Factory<OdometerReadingRecord>
 */
final class OdometerReadingFactory extends Factory
{
    protected $model = OdometerReadingRecord::class;

    public function definition(): array
    {
        return [
            'id' => OdometerReadingId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'km' => $this->faker->numberBetween(0, 500_000),
            'source' => $this->faker->randomElement(array_map(
                static fn (OdometerSource $source): string => $source->value,
                OdometerSource::cases(),
            )),
            'read_at' => $this->faker->dateTimeThisMonth(),
            'note' => null,
        ];
    }

    /**
     * Lectura de un vehículo concreto en un instante dado.
     */
    public function forVehicleAt(string $vehicleId, DateTimeImmutable $readAt): self
    {
        return $this->state(fn (): array => [
            'vehicle_id' => $vehicleId,
            'read_at' => $readAt,
        ]);
    }
}
