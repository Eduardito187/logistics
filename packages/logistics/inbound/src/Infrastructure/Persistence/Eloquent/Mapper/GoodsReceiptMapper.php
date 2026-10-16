<?php

declare(strict_types=1);

namespace Logistics\Inbound\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Inbound\Domain\Model\GoodsReceipt;
use Logistics\Inbound\Domain\ValueObject\AsnId;
use Logistics\Inbound\Domain\ValueObject\GoodsReceiptId;
use Logistics\Inbound\Domain\ValueObject\QcOutcome;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;
use Logistics\Inbound\Domain\ValueObject\ReceivedQuantity;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\GoodsReceiptRecord;
use Logistics\Inbound\Infrastructure\Persistence\Eloquent\Model\ReceiptLineRecord;
use Logistics\Shared\Domain\ValueObject\Ownership;

/**
 * Traduce entre el agregado GoodsReceipt (dominio) y su fila Eloquent + las filas de sus líneas.
 */
final class GoodsReceiptMapper
{
    public function toDomain(GoodsReceiptRecord $record): GoodsReceipt
    {
        $lines = array_values($record->lines
            ->map(static fn (ReceiptLineRecord $line): ReceiptLine => ReceiptLine::of(
                $line->sku,
                ReceivedQuantity::of($line->accepted_quantity, $line->rejected_quantity, $line->damaged_quantity),
                QcOutcome::from($line->qc_outcome),
            ))
            ->all());

        $ownership = Ownership::fromPrimitives(
            $record->fulfillment_mode,
            $record->owner_party_id,
            $record->seller_sku_ref,
        );

        return GoodsReceipt::reconstitute(
            GoodsReceiptId::fromString($record->id),
            AsnId::fromString($record->asn_id),
            $record->warehouse_id,
            $ownership,
            $lines,
            $record->labeled,
        );
    }

    /**
     * Fila de la tabla `inbound_goods_receipts`.
     *
     * @return array{id: string, asn_id: string, warehouse_id: string, owner_party_id: ?string, fulfillment_mode: string, seller_sku_ref: ?string, labeled: bool}
     */
    public function toRow(GoodsReceipt $receipt): array
    {
        $ownership = $receipt->ownership();

        return [
            'id' => $receipt->id()->toString(),
            'asn_id' => $receipt->asnId()->toString(),
            'warehouse_id' => $receipt->warehouseId(),
            'owner_party_id' => $ownership->ownerPartyId?->value,
            'fulfillment_mode' => $ownership->mode->value,
            'seller_sku_ref' => $ownership->sellerSkuRef,
            'labeled' => $receipt->isLabeled(),
        ];
    }

    /**
     * Filas de la tabla `inbound_receipt_lines` para una recepción (sin id: lo pone el repositorio).
     *
     * @return list<array{sku: string, accepted_quantity: int, rejected_quantity: int, damaged_quantity: int, qc_outcome: string}>
     */
    public function toLineRows(GoodsReceipt $receipt): array
    {
        return array_map(
            static fn (ReceiptLine $line): array => [
                'sku' => $line->sku,
                'accepted_quantity' => $line->quantity->accepted,
                'rejected_quantity' => $line->quantity->rejected,
                'damaged_quantity' => $line->quantity->damaged,
                'qc_outcome' => $line->qcOutcome->value,
            ],
            $receipt->lines(),
        );
    }
}
