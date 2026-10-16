<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\ValueObject;

use Logistics\Sellers\Domain\Exception\SellersException;

/**
 * Términos de liquidación pactados con un seller: comisión (en basis points), fee fijo por operación y
 * ciclo de pago. Value object inmutable.
 *
 * La comisión se guarda en **basis points** (1 bp = 0,01 %) como entero para evitar flotantes: 1500 bp =
 * 15,00 %. El tope es 10000 bp (100 %). El cobro real y la liquidación viven en el futuro contexto
 * `settlement` (Fase 4); acá solo se pactan y persisten los términos.
 */
final readonly class SettlementTerms
{
    public const MAX_BASIS_POINTS = 10000;

    private function __construct(
        public int $commissionBasisPoints,
        public Money $fixedFee,
        public PaymentCycle $paymentCycle,
    ) {}

    public static function of(int $commissionBasisPoints, Money $fixedFee, PaymentCycle $paymentCycle): self
    {
        if ($commissionBasisPoints < 0 || $commissionBasisPoints > self::MAX_BASIS_POINTS) {
            throw SellersException::because(
                'La comisión debe estar entre 0 y '.self::MAX_BASIS_POINTS." basis points: {$commissionBasisPoints}.",
            );
        }

        return new self($commissionBasisPoints, $fixedFee, $paymentCycle);
    }

    /**
     * Reconstitución desde primitivos (para mappers de Infrastructure).
     */
    public static function fromPrimitives(
        int $commissionBasisPoints,
        int $fixedFeeCents,
        string $currency,
        string $paymentCycle,
    ): self {
        return self::of(
            $commissionBasisPoints,
            Money::of($fixedFeeCents, $currency),
            PaymentCycle::from($paymentCycle),
        );
    }

    /**
     * Comisión expresada como porcentaje legible (p. ej. 1500 bp → 15.0).
     */
    public function commissionPercent(): float
    {
        return $this->commissionBasisPoints / 100;
    }

    public function equals(self $other): bool
    {
        return $this->commissionBasisPoints === $other->commissionBasisPoints
            && $this->fixedFee->equals($other->fixedFee)
            && $this->paymentCycle === $other->paymentCycle;
    }

    /**
     * @return array{commission_basis_points: int, fixed_fee: array{cents: int, currency: string}, payment_cycle: string}
     */
    public function toArray(): array
    {
        return [
            'commission_basis_points' => $this->commissionBasisPoints,
            'fixed_fee' => $this->fixedFee->toArray(),
            'payment_cycle' => $this->paymentCycle->value,
        ];
    }
}
