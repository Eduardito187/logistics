<?php

declare(strict_types=1);

namespace Logistics\Settlement\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Settlement\Domain\ValueObject\SettlementId;
use Logistics\Settlement\Infrastructure\Persistence\Eloquent\Model\SellerSettlementRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Fábrica de liquidaciones de seller para seeders de demo y pruebas Feature.
 *
 * @extends Factory<SellerSettlementRecord>
 */
final class SellerSettlementFactory extends Factory
{
    protected $model = SellerSettlementRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gross = $this->faker->numberBetween(100_000, 5_000_000);
        $commissionBp = $this->faker->randomElement([1000, 1200, 1500, 1800]);
        $commission = intdiv($gross * $commissionBp, 10000);
        $fixedFee = $this->faker->numberBetween(0, 3000);
        $net = $gross - $commission - $fixedFee;

        return [
            'id' => SettlementId::generate()->toString(),
            'seller_id' => Uuid::generate()->value,
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'gross_cents' => $gross,
            'currency' => 'BOB',
            'commission_basis_points' => $commissionBp,
            'commission_cents' => $commission,
            'fixed_fee_cents' => $fixedFee,
            'storage_fee_cents' => 0,
            'fulfillment_fee_cents' => 0,
            'last_mile_fee_cents' => 0,
            'net_cents' => max($net, 0),
            'status' => 'draft',
            'payment_reference' => null,
        ];
    }

    /**
     * Liquidación de un seller concreto.
     */
    public function forSeller(string $sellerId): self
    {
        return $this->state(fn (): array => ['seller_id' => $sellerId]);
    }

    /**
     * Liquidación confirmada, pendiente de pago.
     */
    public function confirmed(): self
    {
        return $this->state(fn (): array => ['status' => 'confirmed']);
    }

    /**
     * Liquidación pagada, con su referencia de pago.
     */
    public function paid(string $paymentReference = 'PAY-0001'): self
    {
        return $this->state(fn (): array => [
            'status' => 'paid',
            'payment_reference' => $paymentReference,
        ]);
    }
}
