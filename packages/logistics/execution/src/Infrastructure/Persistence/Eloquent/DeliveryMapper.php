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
use Logistics\Shared\Domain\ValueObject\FulfillmentMode;

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
            // Vínculo con las evidencias ya subidas por la app (POD en lote): null si no hay.
            'pod_photo_paths' => $proof !== null && $proof->photoPaths !== [] ? $proof->photoPaths : null,
            'pod_evidence_ids' => $proof !== null && $proof->evidenceIds !== [] ? $proof->evidenceIds : null,
            'failure_reason' => $delivery->failureReason()?->value,
            // Dimensión de fulfillment: solo el modo (dato para reportes/liquidaciones). Las columnas
            // dropship_handling / *_confirmation_key quedan en la tabla sin uso (no se borran: regla aditiva).
            'fulfillment_mode' => $delivery->fulfillmentMode()->value,
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
            // GPS opcional: null = posición desconocida (nunca 0,0 inventado).
            'lat' => $scan->geo?->lat,
            'lng' => $scan->geo?->lng,
            'accuracy_m' => $scan->geo?->accuracyM,
            'location_captured_at' => $scan->geo?->capturedAt?->format(DATE_ATOM),
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
                ScanType::fromLoose($scanRecord->type),
                $scanRecord->unit_id,
                self::geoOf($scanRecord),
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
                array_values(array_map('strval', $record->pod_photo_paths ?? [])),
                array_values(array_map('strval', $record->pod_evidence_ids ?? [])),
            );
        }

        return Delivery::reconstitute(
            DeliveryId::fromString($record->id),
            $record->shipment_id,
            $record->vehicle_id,
            DeliveryStatus::fromLoose($record->status),
            $scans,
            $proof,
            $record->failure_reason !== null ? FailureReason::from($record->failure_reason) : null,
            FulfillmentMode::fromLoose($record->fulfillment_mode),
        );
    }

    /**
     * Posición del escaneo desde su fila: null si no se registró (filas nuevas sin señal).
     */
    private static function geoOf(ScanEventRecord $record): ?GeoPoint
    {
        if ($record->lat === null || $record->lng === null) {
            return null;
        }

        return GeoPoint::of(
            (float) $record->lat,
            (float) $record->lng,
            $record->accuracy_m !== null ? (float) $record->accuracy_m : null,
            $record->location_captured_at !== null ? new DateTimeImmutable($record->location_captured_at) : null,
        );
    }
}
