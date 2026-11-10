<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Execution\Domain\Contract\DeliveryEvidenceRepository;
use Logistics\Execution\Domain\Model\DeliveryEvidence;
use Logistics\Execution\Domain\ValueObject\CustomerIdentitySnapshot;
use Logistics\Execution\Domain\ValueObject\DeliveryEvidenceId;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;
use Logistics\Execution\Domain\ValueObject\GeoPoint;

/**
 * Implementación Eloquent del repositorio de evidencias de entrega.
 *
 * save() hace upsert por id (tolera reintentos y el write-back de validación sobre la misma fila). Toda la
 * conversión a/desde primitivos vive acá; el dominio no conoce la forma de la tabla.
 */
final class EloquentDeliveryEvidenceRepository implements DeliveryEvidenceRepository
{
    public function nextIdentity(): DeliveryEvidenceId
    {
        return DeliveryEvidenceId::generate();
    }

    public function save(DeliveryEvidence $evidence): void
    {
        DeliveryEvidenceRecord::query()->updateOrCreate(
            ['id' => $evidence->id()->toString()],
            [
                'shipment_id' => $evidence->shipmentId(),
                'kind' => $evidence->kind()->value,
                'customer_id' => $evidence->customer()->customerId,
                'customer_document_id' => $evidence->customer()->documentId,
                'customer_name' => $evidence->customer()->name,
                'customer_phone' => $evidence->customer()->phone,
                'storage_disk' => $evidence->storageDisk(),
                'storage_path' => $evidence->storagePath(),
                'sha256' => $evidence->sha256(),
                'captured_by_user' => $evidence->capturedBy()->userId,
                'captured_by_device' => $evidence->capturedBy()->deviceId,
                'lat' => $evidence->location()?->lat,
                'lng' => $evidence->location()?->lng,
                'captured_at' => $evidence->capturedAt()->format(DateTimeInterface::ATOM),
                'idempotency_key' => $evidence->idempotencyKey(),
                'validation_status' => $evidence->validationStatus()->value,
                'validation_detail' => $evidence->validationDetail(),
                'validated_at' => $evidence->validatedAt()?->format(DateTimeInterface::ATOM),
            ],
        );
    }

    public function findById(DeliveryEvidenceId $id): ?DeliveryEvidence
    {
        $record = DeliveryEvidenceRecord::query()->find($id->toString());
        if (!$record instanceof DeliveryEvidenceRecord) {
            return null;
        }

        $location = is_numeric($record->lat) && is_numeric($record->lng)
            ? GeoPoint::of((float) $record->lat, (float) $record->lng)
            : null;

        return DeliveryEvidence::reconstitute(
            DeliveryEvidenceId::fromString($record->id),
            $record->shipment_id,
            EvidenceKind::fromCode($record->kind),
            CustomerIdentitySnapshot::of(
                $record->customer_id,
                $record->customer_document_id,
                $record->customer_name,
                $record->customer_phone,
            ),
            $record->storage_disk,
            $record->storage_path,
            $record->sha256,
            DeviceContext::of(
                ($record->captured_by_device ?? '') !== '' ? (string) $record->captured_by_device : 'unknown-device',
                ($record->captured_by_user ?? '') !== '' ? (string) $record->captured_by_user : 'unknown-user',
            ),
            $location,
            new DateTimeImmutable($record->captured_at),
            $record->idempotency_key,
            EvidenceValidationStatus::fromCode($record->validation_status),
            $record->validation_detail,
            $record->validated_at !== null ? new DateTimeImmutable($record->validated_at) : null,
        );
    }

    public function existsByIdempotencyKey(?string $idempotencyKey): bool
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return false;
        }

        return DeliveryEvidenceRecord::query()
            ->where('idempotency_key', trim($idempotencyKey))
            ->exists();
    }
}
