<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\Model\ScanEvent;
use Logistics\Execution\Domain\ValueObject\DeliveryId;
use Logistics\Execution\Domain\ValueObject\DeliveryStatus;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\FailureReason;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ProofOfDelivery;
use Logistics\Execution\Domain\ValueObject\ScanEventId;
use Logistics\Execution\Domain\ValueObject\ScanType;

/**
 * Traduce entre el agregado Delivery (con sus escaneos) y sus filas Eloquent. Toda la conversión
 * a/desde primitivos vive acá: ni el dominio ni el repositorio conocen la forma de las tablas.
 */
final class DeliveryMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toDeliveryRow(Delivery $delivery): array
    {
        $proof = $delivery->proofOfDelivery();

        return [
            'id' => $delivery->id()->toString(),
            'shipment_id' => $delivery->shipmentId(),
            'vehicle_id' => $delivery->vehicleId(),
            'status' => $delivery->status()->value,
            'pod_confirmation' => $proof?->confirmation,
            'pod_photo_url' => $proof?->photoUrl,
            'pod_photo_path' => $proof?->photoPath,
            'pod_captured_at' => $proof?->capturedAt->format(DATE_ATOM),
            'failure_reason' => $delivery->failureReason()?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toScanRow(ScanEvent $scan): array
    {
        return [
            'id' => $scan->id->toString(),
            'delivery_id' => $scan->deliveryId,
            'type' => $scan->type->value,
            'unit_id' => $scan->unitId,
            'lat' => $scan->geo->lat,
            'lng' => $scan->geo->lng,
            'device_id' => $scan->device->deviceId,
            'user_id' => $scan->device->userId,
            'occurred_at' => $scan->occurredAt->format(DATE_ATOM),
            'idempotency_key' => $scan->idempotencyKey,
        ];
    }

    /**
     * Reconstruye el agregado completo (con sus escaneos) desde sus filas.
     */
    public function toDomain(DeliveryRecord $record): Delivery
    {
        $scans = [];
        foreach ($record->scans as $scanRecord) {
            $scans[] = ScanEvent::record(
                ScanEventId::fromString($scanRecord->id),
                $scanRecord->delivery_id,
                ScanType::from($scanRecord->type),
                $scanRecord->unit_id,
                GeoPoint::of((float) $scanRecord->lat, (float) $scanRecord->lng),
                DeviceContext::of($scanRecord->device_id, $scanRecord->user_id),
                new DateTimeImmutable($scanRecord->occurred_at),
                $scanRecord->idempotency_key,
            );
        }

        $proof = null;
        if ($record->pod_confirmation !== null && $record->pod_captured_at !== null) {
            $proof = ProofOfDelivery::of(
                $record->pod_confirmation,
                $record->pod_photo_url,
                new DateTimeImmutable($record->pod_captured_at),
                $record->pod_photo_path,
            );
        }

        return Delivery::reconstitute(
            DeliveryId::fromString($record->id),
            $record->shipment_id,
            $record->vehicle_id,
            DeliveryStatus::from($record->status),
            $scans,
            $proof,
            $record->failure_reason !== null ? FailureReason::from($record->failure_reason) : null,
        );
    }
}
