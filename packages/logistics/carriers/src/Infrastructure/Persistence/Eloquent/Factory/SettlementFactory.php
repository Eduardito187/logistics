<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory;

use Illuminate\Database\Eloquent\Factories\Factory;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\SettlementId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\SettlementRecord;

/**
 * Fábrica de liquidaciones de transportista para seeders de demo y pruebas Feature.
 *
 * @extends Factory<SettlementRecord>
 */
final class SettlementFactory extends Factory
{
    protected $model = SettlementRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => SettlementId::generate()->toString(),
            'carrier_id' => CarrierId::generate()->toString(),
            'corridor_from' => 'node-'.$this->faker->bothify('???-##'),
            'corridor_to' => 'node-'.$this->faker->bothify('???-##'),
            'corridor_type' => 'cd_to_cd',
            'period' => sprintf('2026-%02d', $this->faker->numberBetween(1, 12)),
            'amount_cents' => $this->faker->numberBetween(100_000, 5_000_000),
            'currency' => 'BOB',
            'reference' => 'INV-'.$this->faker->unique()->numerify('######'),
            'status' => 'submitted',
            'dispute_reason' => null,
            'payment_reference' => null,
            'documents' => [],
        ];
    }

    /**
     * Liquidación de un transportista concreto.
     */
    public function forCarrier(string $carrierId): self
    {
        return $this->state(fn (): array => ['carrier_id' => $carrierId]);
    }

    /**
     * Liquidación de un corredor, período y monto concretos.
     */
    public function forCorridorState(string $from, string $to, string $type, string $period, int $amountCents): self
    {
        return $this->state(fn (): array => [
            'corridor_from' => $from,
            'corridor_to' => $to,
            'corridor_type' => $type,
            'period' => $period,
            'amount_cents' => $amountCents,
        ]);
    }

    /**
     * Liquidación aprobada, pendiente de pago.
     */
    public function approved(): self
    {
        return $this->state(fn (): array => ['status' => 'approved']);
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

    /**
     * Liquidación objetada, con su motivo.
     */
    public function disputed(string $reason = 'Amount mismatch'): self
    {
        return $this->state(fn (): array => [
            'status' => 'disputed',
            'dispute_reason' => $reason,
        ]);
    }
}
