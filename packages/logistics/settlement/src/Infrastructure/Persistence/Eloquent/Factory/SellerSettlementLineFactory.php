<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementLineRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de líneas de liquidación de seller para seeders de demo y pruebas Feature.
 *
 * @extends Factory<SellerSettlementLineRecord>
 */
final class SellerSettlementLineFactory extends Factory
{
    protected $model = SellerSettlementLineRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => Uuid::generate()->value,
            'settlement_id' => Uuid::generate()->value,
            'shipment_id' => Uuid::generate()->value,
            'dispatch_reference' => 'ORD-'.$this->faker->unique()->numerify('######'),
            'base_amount_cents' => $this->faker->numberBetween(10_000, 1_000_000),
            'currency' => 'BOB',
        ];
    }

    /**
     * Línea de una liquidación concreta.
     */
    public function forSettlement(string $settlementId): self
    {
        return $this->state(fn (): array => ['settlement_id' => $settlementId]);
    }
}
