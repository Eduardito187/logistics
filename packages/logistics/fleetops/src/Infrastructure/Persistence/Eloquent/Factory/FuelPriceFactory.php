<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;

/**
 * Fábrica de precios de combustible para seeders de demo y pruebas Feature.
 *
 * @extends Factory<FuelPriceRecord>
 */
final class FuelPriceFactory extends Factory
{
    protected $model = FuelPriceRecord::class;

    public function definition(): array
    {
        return [
            'id' => FuelPriceId::generate()->toString(),
            'fuel_type' => $this->faker->randomElement([
                FuelType::GasolineSpecial->value,
                FuelType::Diesel->value,
                FuelType::Gnv->value,
            ]),
            'price_cents' => $this->faker->numberBetween(300, 1_200),
            'currency' => 'BOB',
            'effective_from' => '2026-01-01',
            'source' => $this->faker->randomElement(['ANH', 'manual', null]),
        ];
    }

    /**
     * Precio de un tipo de combustible concreto.
     */
    public function forFuelType(string $fuelType): self
    {
        return $this->state(fn (): array => ['fuel_type' => $fuelType]);
    }

    /**
     * Precio por litro concreto en centavos.
     */
    public function priced(int $cents, string $currency = 'BOB'): self
    {
        return $this->state(fn (): array => [
            'price_cents' => $cents,
            'currency' => $currency,
        ]);
    }

    /**
     * Vigente desde una fecha concreta (Y-m-d).
     */
    public function effectiveFrom(string $date): self
    {
        return $this->state(fn (): array => ['effective_from' => $date]);
    }
}
