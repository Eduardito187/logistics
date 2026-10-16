<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\ValueObject;

/**
 * Fees aplicados a una liquidación de seller, ya calculados como montos concretos.
 *
 * - `commissionBasisPoints`: la tasa pactada (1 bp = 0,01 %), para trazabilidad del cálculo.
 * - `commission`: la comisión del marketplace sobre el bruto (= bruto · bp / 10000).
 * - `fixedFee`: el fee fijo total (fee por despacho · nº de despachos).
 *
 * HUECO TIPADO (TODO Fases 2–3): `storageFee`, `fulfillmentFee` y `lastMileFee` ya existen como campos
 * tipados y se descuentan del neto, pero HOY valen SIEMPRE 0. Se poblarán cuando `inventory` (almacenaje)
 * e `inbound`/última milla expongan sus costos por seller/periodo. Mantenerlos ahora deja el contrato de
 * datos estable para cuando se activen (ADR 017, roadmap §7).
 */
final readonly class AppliedFees
{
    private function __construct(
        public int $commissionBasisPoints,
        public Money $commission,
        public Money $fixedFee,
        public Money $storageFee,
        public Money $fulfillmentFee,
        public Money $lastMileFee,
    ) {}

    /**
     * Construye los fees aplicados. Los fees diferidos arrancan en 0 en la moneda de la comisión.
     */
    public static function of(
        int $commissionBasisPoints,
        Money $commission,
        Money $fixedFee,
        ?Money $storageFee = null,
        ?Money $fulfillmentFee = null,
        ?Money $lastMileFee = null,
    ): self {
        $zero = Money::zero($commission->currency);

        return new self(
            $commissionBasisPoints,
            $commission,
            $fixedFee,
            $storageFee ?? $zero,
            $fulfillmentFee ?? $zero,
            $lastMileFee ?? $zero,
        );
    }

    /**
     * Suma total de todos los fees (lo que Dismac retiene del bruto del seller).
     */
    public function total(): Money
    {
        return $this->commission
            ->add($this->fixedFee)
            ->add($this->storageFee)
            ->add($this->fulfillmentFee)
            ->add($this->lastMileFee);
    }

    /**
     * @return array{commission_basis_points: int, commission_cents: int, fixed_fee_cents: int, storage_fee_cents: int, fulfillment_fee_cents: int, last_mile_fee_cents: int, total_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'commission_basis_points' => $this->commissionBasisPoints,
            'commission_cents' => $this->commission->cents,
            'fixed_fee_cents' => $this->fixedFee->cents,
            'storage_fee_cents' => $this->storageFee->cents,
            'fulfillment_fee_cents' => $this->fulfillmentFee->cents,
            'last_mile_fee_cents' => $this->lastMileFee->cents,
            'total_cents' => $this->total()->cents,
            'currency' => $this->commission->currency,
        ];
    }
}
