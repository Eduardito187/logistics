<?php

declare(strict_types=1);

namespace Logistics\Settlement\Application\Command\DraftSellerSettlement;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de armar el borrador de una liquidación para un seller en un periodo.
 *
 * Los términos de liquidación (comisión en basis points, fee fijo por despacho, moneda) llegan como input
 * del comando a propósito: `settlement` no depende del contexto `sellers`; el integrador (o el admin) los
 * toma de los `SettlementTerms` del seller y los pasa acá. El periodo son dos fechas `YYYY-MM-DD`.
 */
final readonly class DraftSellerSettlement implements Command
{
    public function __construct(
        public string $sellerId,
        public string $periodFrom,
        public string $periodTo,
        public int $commissionBasisPoints,
        public int $fixedFeeCents,
        public string $currency = 'BOB',
    ) {}
}
