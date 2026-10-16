<?php

declare(strict_types=1);

namespace Logistics\Returns\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Returns\Domain\Model\ReturnOrder;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnId;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnLineRecord;
use Logistics\Returns\Infrastructure\Persistence\Eloquent\Model\ReturnRecord;

/**
 * Traduce entre el agregado ReturnOrder (dominio) y su fila Eloquent + las filas de sus líneas.
 */
final class ReturnOrderMapper
{
    public function toDomain(ReturnRecord $record): ReturnOrder
    {
        $lines = array_values($record->lines
            ->map(static fn (ReturnLineRecord $line): ReturnLine => ReturnLine::of($line->sku, $line->quantity, $line->reason))
            ->all());

        return ReturnOrder::reconstitute(
            ReturnId::fromString($record->id),
            $record->original_reference,
            $record->owner_party_id,
            $record->warehouse_id,
            ReturnKind::from($record->kind),
            $lines,
            ReturnStatus::from($record->status),
            $record->disposition !== null ? Disposition::from($record->disposition) : null,
        );
    }

    /**
     * Fila de la tabla `returns`.
     *
     * @return array{id: string, original_reference: string, owner_party_id: ?string, warehouse_id: string, fulfillment_mode: string, kind: string, disposition: ?string, status: string}
     */
    public function toRow(ReturnOrder $returnOrder): array
    {
        return [
            'id' => $returnOrder->id()->toString(),
            'original_reference' => $returnOrder->originalReference(),
            'owner_party_id' => $returnOrder->ownerPartyId(),
            'warehouse_id' => $returnOrder->warehouseId(),
            'fulfillment_mode' => $returnOrder->ownership()->mode->value,
            'kind' => $returnOrder->kind()->value,
            'disposition' => $returnOrder->disposition()?->value,
            'status' => $returnOrder->status()->value,
        ];
    }

    /**
     * Filas de la tabla `return_lines` para una devolución (sin id: lo pone el repositorio).
     *
     * @return list<array{sku: string, quantity: int, reason: string}>
     */
    public function toLineRows(ReturnOrder $returnOrder): array
    {
        return array_map(
            static fn (ReturnLine $line): array => $line->toArray(),
            $returnOrder->lines(),
        );
    }
}
