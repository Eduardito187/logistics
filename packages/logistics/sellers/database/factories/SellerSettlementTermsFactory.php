<?php

declare(strict_types=1);

namespace Logistics\Sellers\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Sellers\Domain\ValueObject\SellerId;
use Logistics\Sellers\Infrastructure\Persistence\Eloquent\Model\SellerSettlementTermsRecord;

/**
 * Fábrica de términos de liquidación para seeders de demo y pruebas Feature.
 *
 * @extends Factory<SellerSettlementTermsRecord>
 */
final class SellerSettlementTermsFactory extends Factory
{
    protected $model = SellerSettlementTermsRecord::class;

    public function definition(): array
    {
        return [
            'id' => SellerId::generate()->toString(),
            'seller_id' => SellerId::generate()->toString(),
            'commission_basis_points' => $this->faker->numberBetween(500, 2500),
            'fixed_fee_cents' => $this->faker->numberBetween(0, 5000),
            'currency' => 'BOB',
            'payment_cycle' => $this->faker->randomElement(['weekly', 'biweekly', 'monthly']),
        ];
    }

    /**
     * Términos para un seller concreto.
     */
    public function forSeller(string $sellerId): self
    {
        return $this->state(fn (): array => ['seller_id' => $sellerId]);
    }
}
