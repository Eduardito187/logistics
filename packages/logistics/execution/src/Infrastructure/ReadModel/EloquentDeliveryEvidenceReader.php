<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Execution\Application\Contract\DeliveryEvidenceReader;
use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryEvidenceRecord;

/**
 * Lectura Eloquent de las evidencias de un envío (lado de lectura CQRS), más recientes primero.
 */
final class EloquentDeliveryEvidenceReader implements DeliveryEvidenceReader
{
    public function forShipment(string $shipmentId): array
    {
        return array_values(DeliveryEvidenceRecord::query()
            ->where('shipment_id', $shipmentId)
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (DeliveryEvidenceRecord $record): DeliveryEvidenceView => $this->toView($record))
            ->all());
    }

    private function toView(DeliveryEvidenceRecord $record): DeliveryEvidenceView
    {
        return new DeliveryEvidenceView(
            id: $record->id,
            shipmentId: $record->shipment_id,
            kind: $record->kind,
            validationStatus: $record->validation_status,
            validationDetail: $record->validation_detail,
            sha256: $record->sha256,
            storageDisk: $record->storage_disk,
            storagePath: $record->storage_path,
            customer: [
                'id' => $record->customer_id,
                'document_id' => $record->customer_document_id,
                'name' => $record->customer_name,
                'phone' => $record->customer_phone,
            ],
            location: [
                'lat' => is_numeric($record->lat) ? (float) $record->lat : null,
                'lng' => is_numeric($record->lng) ? (float) $record->lng : null,
            ],
            capturedAt: $this->iso($record->captured_at),
            validatedAt: $this->iso($record->validated_at),
        );
    }

    private function iso(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
    }
}
