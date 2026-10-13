<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;

/**
 * Fábrica de órdenes de mantenimiento para seeders de demo y pruebas Feature.
 *
 * @extends Factory<MaintenanceOrderRecord>
 */
final class MaintenanceOrderFactory extends Factory
{
    protected $model = MaintenanceOrderRecord::class;

    public function definition(): array
    {
        return [
            'id' => MaintenanceOrderId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'type' => MaintenanceType::Preventive->value,
            'status' => MaintenanceStatus::Scheduled->value,
            'odometer_km' => $this->faker->numberBetween(10_000, 200_000),
            'workshop' => $this->faker->company(),
            'items' => [],
            'total_cost_cents' => 0,
            'currency' => 'BOB',
            'scheduled_at' => $this->faker->dateTimeBetween('-1 month', '+1 month'),
            'started_at' => null,
            'completed_at' => null,
            'note' => null,
        ];
    }

    /**
     * Orden de un vehículo concreto.
     */
    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    /**
     * Orden en un estado concreto.
     */
    public function withStatus(MaintenanceStatus $status): self
    {
        return $this->state(fn (): array => ['status' => $status->value]);
    }

    /**
     * Orden completada con un costo total concreto (centavos).
     *
     * @param list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}> $items
     */
    public function completed(int $totalCostCents, array $items = [], string $currency = 'BOB'): self
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceStatus::Completed->value,
            'items' => $items,
            'total_cost_cents' => $totalCostCents,
            'currency' => $currency,
            'completed_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ]);
    }
}
