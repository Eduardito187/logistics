<?php

declare(strict_types=1);

namespace Logistics\Fleet\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\PreTripInspectionModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Factory de la fila `fleet_pre_trip_checklists`. Produce inspecciones pre-salida de demo con un
 * checklist estándar; por defecto todas aprobadas (todos los ítems verificados).
 *
 * `passed` se mantiene coherente con los ítems (todos true → aprobada): es el campo desnormalizado
 * que el dominio deriva de que el checklist quede completo.
 *
 * @extends Factory<PreTripInspectionModel>
 */
final class PreTripInspectionFactory extends Factory
{
    protected $model = PreTripInspectionModel::class;

    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'vehicle_id' => Uuid::generate()->value,
            'driver_id' => Uuid::generate()->value,
            'items' => [
                'Tires' => true,
                'Brakes' => true,
                'Lights' => true,
                'Fuel level' => true,
                'Documents on board' => true,
            ],
            'passed' => true,
            'performed_on' => $this->faker->dateTimeBetween('-2 weeks', 'now')->format('Y-m-d'),
            'notes' => null,
        ];
    }

    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    public function forDriver(string $driverId): self
    {
        return $this->state(fn (): array => ['driver_id' => $driverId]);
    }

    /**
     * Inspección no apta: queda un ítem sin verificar.
     */
    public function failed(): self
    {
        return $this->state(fn (): array => [
            'items' => [
                'Tires' => true,
                'Brakes' => false,
                'Lights' => true,
                'Fuel level' => true,
                'Documents on board' => true,
            ],
            'passed' => false,
            'notes' => 'Brakes need review before dispatch.',
        ]);
    }
}
