<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\TariffId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\TariffRecord;

/**
 * Fábrica de tarifas por corredor para seeders de demo y pruebas Feature.
 *
 * @extends Factory<TariffRecord>
 */
final class TariffFactory extends Factory
{
    protected $model = TariffRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => TariffId::generate()->toString(),
            'carrier_id' => CarrierId::generate()->toString(),
            'corridor_from' => 'node-'.$this->faker->bothify('???-##'),
            'corridor_to' => 'node-'.$this->faker->bothify('???-##'),
            'corridor_type' => 'cd_to_cd',
            'vehicle_class' => 'trailer',
            'price_cents' => $this->faker->numberBetween(50_000, 900_000),
            'currency' => 'BOB',
            'effective_from' => '2026-01-01',
        ];
    }

    /**
     * Tarifa de un transportista concreto.
     */
    public function forCarrier(string $carrierId): self
    {
        return $this->state(fn (): array => ['carrier_id' => $carrierId]);
    }

    /**
     * Tarifa para un corredor concreto (origen, destino, tipo).
     */
    public function forCorridor(string $from, string $to, string $type): self
    {
        return $this->state(fn (): array => [
            'corridor_from' => $from,
            'corridor_to' => $to,
            'corridor_type' => $type,
        ]);
    }

    /**
     * Tarifa para una clase de vehículo concreta.
     */
    public function forVehicleClass(string $vehicleClass): self
    {
        return $this->state(fn (): array => ['vehicle_class' => $vehicleClass]);
    }

    /**
     * Tarifa con un precio concreto en centavos.
     */
    public function priced(int $cents, string $currency = 'BOB'): self
    {
        return $this->state(fn (): array => [
            'price_cents' => $cents,
            'currency' => $currency,
        ]);
    }
}
