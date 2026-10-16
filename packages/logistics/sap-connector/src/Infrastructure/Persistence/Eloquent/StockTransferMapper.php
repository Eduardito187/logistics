<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Persistence\Eloquent;

use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\SapCenter;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapWarehouse;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\SapConnector\Domain\ValueObject\TransferKind;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;

/**
 * Traduce entre el agregado StockTransfer y su fila Eloquent. Toda la conversión a/desde primitivos
 * vive acá, para que ni el dominio ni el repositorio conozcan la forma de la tabla.
 */
final class StockTransferMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id()->toString(),
            'event_id' => $transfer->eventId(),
            'shipment_ref' => $transfer->shipmentRef(),
            'material' => $transfer->material()->value,
            'center' => $transfer->center()->value,
            'warehouse' => $transfer->warehouse()->value,
            'quantity' => $transfer->quantity(),
            'status' => $transfer->status()->value,
            'last_error' => $transfer->lastError(),
            'transfer_kind' => $transfer->kind()->value,
            'owner_party_id' => $transfer->ownerPartyId(),
        ];
    }

    public function toDomain(StockTransferRecord $record): StockTransfer
    {
        return StockTransfer::reconstitute(
            StockTransferId::fromString($record->id),
            $record->event_id,
            $record->shipment_ref,
            SapMaterial::of($record->material),
            SapCenter::of($record->center),
            SapWarehouse::of($record->warehouse),
            $record->quantity,
            TransferStatus::from($record->status),
            $record->last_error,
            TransferKind::from($record->transfer_kind),
            $record->owner_party_id,
        );
    }
}
