<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseCategory;
use Logistics\FleetOps\Domain\ValueObject\ExpenseId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\ExpenseRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de gastos de flota para seeders de demo y pruebas Feature.
 *
 * @extends Factory<ExpenseRecord>
 */
final class ExpenseFactory extends Factory
{
    protected $model = ExpenseRecord::class;

    public function definition(): array
    {
        return [
            'id' => ExpenseId::generate()->toString(),
            'vehicle_id' => Uuid::generate()->value,
            'category' => $this->faker->randomElement(ExpenseCategory::cases())->value,
            'amount_cents' => $this->faker->numberBetween(1_000, 500_000),
            'currency' => 'BOB',
            'description' => $this->faker->optional()->sentence(4),
            'incurred_at' => $this->faker->dateTimeBetween('-6 months')->format('Y-m-d H:i:s'),
            'note' => $this->faker->optional()->sentence(3),
        ];
    }

    /**
     * Gasto de un vehículo concreto (por su id de fleet).
     */
    public function forVehicle(string $vehicleId): self
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicleId]);
    }

    /**
     * Gasto de una categoría concreta.
     */
    public function ofCategory(string $category): self
    {
        return $this->state(fn (): array => ['category' => $category]);
    }

    /**
     * Gasto con un importe concreto en centavos.
     */
    public function priced(int $cents, string $currency = 'BOB'): self
    {
        return $this->state(fn (): array => [
            'amount_cents' => $cents,
            'currency' => $currency,
        ]);
    }
}
