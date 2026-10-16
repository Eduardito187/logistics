<?php

declare(strict_types=1);

namespace Logistics\Returns\Application\ReadModel;

use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\ReturnLine;

/**
 * Proyección de lectura de una devolución para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class ReturnView
{
    /**
     * @param list<array{sku: string, quantity: int, reason: string}> $lines
     */
    private function __construct(
        public string $id,
        public string $originalReference,
        public ?string $sellerId,
        public string $warehouseId,
        public string $fulfillmentMode,
        public string $kind,
        public ?string $disposition,
        public string $status,
        public array $lines,
    ) {}

    public static function fromDomain(ReturnOrder $returnOrder): self
    {
        return new self(
            $returnOrder->id()->toString(),
            $returnOrder->originalReference(),
            $returnOrder->ownerPartyId(),
            $returnOrder->warehouseId(),
            $returnOrder->ownership()->mode->value,
            $returnOrder->kind()->value,
            $returnOrder->disposition()?->value,
            $returnOrder->status()->value,
            array_map(static fn (ReturnLine $line): array => $line->toArray(), $returnOrder->lines()),
        );
    }

    /**
     * @return array{id: string, original_reference: string, seller_id: string|null, warehouse_id: string, fulfillment_mode: string, kind: string, disposition: string|null, status: string, lines: list<array{sku: string, quantity: int, reason: string}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'original_reference' => $this->originalReference,
            'seller_id' => $this->sellerId,
            'warehouse_id' => $this->warehouseId,
            'fulfillment_mode' => $this->fulfillmentMode,
            'kind' => $this->kind,
            'disposition' => $this->disposition,
            'status' => $this->status,
            'lines' => $this->lines,
        ];
    }
}
