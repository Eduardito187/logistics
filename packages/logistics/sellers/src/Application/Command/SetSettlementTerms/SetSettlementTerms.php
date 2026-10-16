<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\Command\SetSettlementTerms;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de pactar o actualizar los términos de liquidación de un seller.
 *
 * La comisión llega en basis points (1 bp = 0,01 %); el fee fijo en centavos + moneda.
 */
final readonly class SetSettlementTerms implements Command
{
    public function __construct(
        public string $sellerId,
        public int $commissionBasisPoints,
        public int $fixedFeeCents,
        public string $currency,
        public string $paymentCycle,
    ) {}
}
