<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;

/**
 * Fábrica de planes de mantenimiento preventivo para seeders de demo y pruebas Feature.
 *
 * @extends Factory<MaintenancePlanRecord>
 */
final class MaintenancePlanFactory extends Factory
{
    protected $model = MaintenancePlanRecord::class;

    public function definition(): array
    {
        return [
            'id' => MaintenancePlanId::generate()->toString(),
            'vehicle_id' => $this->faker->uuid(),
            'service_kind' => ServiceKind::OilChange->value,
            'interval_km' => 10_000,
            'interval_days' => 180,
            'last_done_km' => null,
            'last_done_at' => null,
            'next_due_km' => null,
            'next_due_at' => null,
            'active' => true,
        ];
    }

    /**
     * Plan de un vehículo concreto.
     */
    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    /**
     * Plan de una clase de servicio concreta.
     */
    public function ofKind(ServiceKind $serviceKind): self
    {
        return $this->state(fn (): array => ['service_kind' => $serviceKind->value]);
    }

    /**
     * Plan desactivado.
     */
    public function inactive(): self
    {
        return $this->state(fn (): array => ['active' => false]);
    }
}
