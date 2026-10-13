<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `fleet_crews`. Produce tripulaciones de demo (chofer + vehículo + fecha); por
 * defecto sin ayudantes. Los ids de chofer/vehículo son UUID sueltos: en los seeders se fijan con
 * `forDriver`/`forVehicle` a entidades reales para mantener la coherencia.
 *
 * @extends Factory<CrewModel>
 */
final class CrewFactory extends Factory
{
    protected $model = CrewModel::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'driver_id' => Uuid::generate()->value,
            'helper_ids' => [],
            'vehicle_id' => Uuid::generate()->value,
            'assembled_for' => $this->faker->dateTimeBetween('-1 week', '+1 week')->format('Y-m-d'),
            'shift_starts_at' => null,
            'shift_ends_at' => null,
        ];
    }

    /**
     * Fija el turno de trabajo (horas de salida y regreso) en formato texto de fecha-hora.
     */
    public function withShift(string $startsAt, string $endsAt): self
    {
        return $this->state(fn (): array => [
            'shift_starts_at' => $startsAt,
            'shift_ends_at' => $endsAt,
        ]);
    }

    public function forDriver(string $driverId): self
    {
        return $this->state(fn (): array => ['driver_id' => $driverId]);
    }

    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    /**
     * @param list<string> $helperIds
     */
    public function withHelpers(array $helperIds): self
    {
        return $this->state(fn (): array => ['helper_ids' => array_values($helperIds)]);
    }

    public function assembledFor(string $date): self
    {
        return $this->state(fn (): array => ['assembled_for' => $date]);
    }
}
