<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\ReadModel;

use Logistics\Inbound\Domain\Model\GoodsReceipt;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;

/**
 * Proyección de lectura de una recepción (goods receipt) con su detalle por línea y QC. Solo primitivos.
 */
final readonly class GoodsReceiptView
{
    /**
     * @param list<array{sku: string, quantity: array{accepted: int, rejected: int, damaged: int}, qc_outcome: string}> $lines
     */
    private function __construct(
        public string $id,
        public string $asnId,
        public string $warehouseId,
        public string $sellerId,
        public string $fulfillmentMode,
        public bool $labeled,
        public int $totalAccepted,
        public array $lines,
    ) {}

    public static function fromDomain(GoodsReceipt $receipt): self
    {
        $ownership = $receipt->ownership();
        // Una recepción de inbound es siempre de un seller (FBD/dropship): el dueño nunca es null (lo
        // garantiza GoodsReceipt::record). Se lee directo; si algún día fuera retail, el VO ya habría fallado.
        $ownerPartyId = $ownership->ownerPartyId;

        return new self(
            $receipt->id()->toString(),
            $receipt->asnId()->toString(),
            $receipt->warehouseId(),
            $ownerPartyId !== null ? $ownerPartyId->value : '',
            $ownership->mode->value,
            $receipt->isLabeled(),
            $receipt->totalAccepted(),
            array_map(static fn (ReceiptLine $line): array => $line->toArray(), $receipt->lines()),
        );
    }

    /**
     * @return array{id: string, asn_id: string, warehouse_id: string, seller_id: string, fulfillment_mode: string, labeled: bool, total_accepted: int, lines: list<array{sku: string, quantity: array{accepted: int, rejected: int, damaged: int}, qc_outcome: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'asn_id' => $this->asnId,
            'warehouse_id' => $this->warehouseId,
            'seller_id' => $this->sellerId,
            'fulfillment_mode' => $this->fulfillmentMode,
            'labeled' => $this->labeled,
            'total_accepted' => $this->totalAccepted,
            'lines' => $this->lines,
        ];
    }
}
