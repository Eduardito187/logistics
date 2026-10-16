<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\RecordGoodsReceipt;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar la recepción física de un ASN con control de calidad por línea.
 *
 * Cada línea trae el sku, su desglose de cantidades (aceptada/rechazada/dañada) y el resultado de QC.
 */
final readonly class RecordGoodsReceipt implements Command
{
    /**
     * @param list<array{sku: string, accepted: int, rejected: int, damaged: int, qc_outcome: string}> $lines
     */
    public function __construct(
        public string $asnId,
        public array $lines,
        public ?string $sellerSkuRef = null,
    ) {}
}
