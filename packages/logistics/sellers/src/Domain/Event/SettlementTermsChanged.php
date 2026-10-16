<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambiaron los términos de liquidación pactados con el seller (comisión, fee fijo, ciclo de pago).
 */
final readonly class SettlementTermsChanged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public int $commissionBasisPoints,
        public int $fixedFeeCents,
        public string $currency,
        public string $paymentCycle,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'sellers.settlement_terms_changed';
    }

    public function payload(): array
    {
        return [
            'commission_basis_points' => $this->commissionBasisPoints,
            'fixed_fee_cents' => $this->fixedFeeCents,
            'currency' => $this->currency,
            'payment_cycle' => $this->paymentCycle,
        ];
    }
}
