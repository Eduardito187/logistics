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
use Logistics\Execution\Domain\ValueObject\MissingUnitDeclaration;
use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
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

        $row = [
            'id' => $delivery->id()->toString(),
            'shipment_id' => $delivery->shipmentId(),
            // Entrega de mostrador (retiro en tienda): sin vehículo se guarda NULL.
            'vehicle_id' => $delivery->isCounterHandover() ? null : $delivery->vehicleId(),
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
            // Entrega en tienda del chofer (pedido que el cliente retira en la tienda).
            'store_dropped_at' => $delivery->storeDroppedAt()?->format(DATE_ATOM),
            'store_drop_node_id' => $delivery->storeDropNodeId(),
            'store_drop_photo_path' => $delivery->storeDropPhotoPath(),
        ];

        // Faltantes de la entrega en tienda: la columna solo se escribe cuando el chofer declaró alguno, así
        // el guardado de siempre no depende de ella.
        $missing = $delivery->storeDropMissingUnits();
        if ($missing !== []) {
            $row['store_drop_missing_units'] = array_map(
                static fn (MissingUnitDeclaration $declaration): array => $declaration->toArray(),
                $missing,
            );
        }

        // Cierre con faltantes de un traspaso: igual criterio, las columnas solo se escriben cuando ocurrió.
        if ($delivery->isClosedWithShortage()) {
            $row['closed_with_shortage'] = true;
            $row['shortage_closed_at'] = $delivery->shortageClosedAt()?->format(DATE_ATOM);
            $row['shortage_close_reason'] = $delivery->shortageCloseReason();
            $row['shortage_closed_by'] = $delivery->shortageClosedBy();
        }

        return $row;
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

        $delivery = Delivery::reconstitute(
            DeliveryId::fromString($record->id),
            $record->shipment_id,
            (string) ($record->vehicle_id ?? ''),
            DeliveryStatus::fromLoose($record->status),
            $scans,
            $proof,
            $record->failure_reason !== null ? FailureReason::from($record->failure_reason) : null,
            FulfillmentMode::fromLoose($record->fulfillment_mode),
        );

        $droppedAt = $record->getAttribute('store_dropped_at');
        $dropNode = $record->getAttribute('store_drop_node_id');
        $dropPhoto = $record->getAttribute('store_drop_photo_path');
        $delivery->restoreStoreDrop(
            is_string($droppedAt) && $droppedAt !== '' ? new DateTimeImmutable($droppedAt) : null,
            is_string($dropNode) && $dropNode !== '' ? $dropNode : null,
            is_string($dropPhoto) && $dropPhoto !== '' ? $dropPhoto : null,
            self::missingUnits($record->getAttribute('store_drop_missing_units')),
        );

        // Cierre con faltantes (columnas ausentes si la migración todavía no corrió: queda en «no»).
        $shortageAt = $record->getAttribute('shortage_closed_at');
        $shortageReason = $record->getAttribute('shortage_close_reason');
        $shortageBy = $record->getAttribute('shortage_closed_by');
        $delivery->restoreShortageClosure(
            in_array($record->getAttribute('closed_with_shortage'), [true, 1, '1', 't', 'true'], true),
            is_string($shortageAt) && $shortageAt !== '' ? new DateTimeImmutable($shortageAt) : null,
            is_string($shortageReason) && $shortageReason !== '' ? $shortageReason : null,
            is_string($shortageBy) && $shortageBy !== '' ? $shortageBy : null,
        );

        return $delivery;
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

    /**
     * Faltantes de la entrega en tienda desde su columna JSON. Tolerante: ignora lo que no tenga forma válida
     * (y la columna ausente, si la migración todavía no corrió).
     *
     * @return list<MissingUnitDeclaration>
     */
    private static function missingUnits(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $declared = [];
        foreach ($raw as $item) {
            $unitId = is_array($item) ? ($item['unit_id'] ?? null) : null;
            $reason = is_array($item) && is_string($item['reason'] ?? null) ? MissingUnitReason::tryFrom($item['reason']) : null;
            if (is_string($unitId) && trim($unitId) !== '' && $reason !== null) {
                $declared[] = MissingUnitDeclaration::of($unitId, $reason);
            }
        }

        return $declared;
    }
}
