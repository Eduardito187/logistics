<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\MissingUnitDeclaration;
use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;
use Logistics\Execution\Domain\ValueObject\ReceivedUnit;
use Logistics\Execution\Domain\ValueObject\ReconciliationReport;
use Logistics\Execution\Domain\ValueObject\ShelfLocation;
use Logistics\Execution\Domain\ValueObject\SurplusUnitDeclaration;

/**
 * Traduce entre el agregado NodeReception (con sus bultos recibidos) y sus filas Eloquent. Toda la
 * conversión a/desde primitivos vive acá: ni el dominio ni el repositorio conocen la forma de las tablas.
 */
final class NodeReceptionMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toReceptionRow(NodeReception $reception): array
    {
        $report = $reception->reconciliationReport();

        return [
            'id' => $reception->id()->toString(),
            'shipment_id' => $reception->shipmentId(),
            'origin_node_id' => $reception->originNodeId(),
            'destination_node_id' => $reception->destinationNodeId(),
            'corridor' => $reception->corridor()->value,
            'status' => $reception->status()->value,
            'expected_unit_ids' => $reception->expectedUnitIds(),
            'opened_at' => $reception->openedAt()->format(DATE_ATOM),
            'reconciled_at' => $reception->reconciledAt()?->format(DATE_ATOM),
            'reconciliation_notes' => $report->notes,
            'reconciliation_photo_path' => $report->photoPath,
            'missing_units' => $report->missing === [] ? null : $report->missingToArray(),
            'surplus_units' => $report->surplus === [] ? null : $report->surplusToArray(),
        ];
    }

    /**
     * Fila de un bulto recibido (sin id: el repositorio lo genera al insertar, append-only por clave de
     * idempotencia). Sin GPS, lat/lng quedan NULL (nunca 0,0).
     *
     * @return array<string, mixed>
     */
    public function toUnitRow(NodeReception $reception, ReceivedUnit $unit): array
    {
        return [
            'node_reception_id' => $reception->id()->toString(),
            'unit_id' => $unit->unitId,
            'lat' => $unit->geo?->lat,
            'lng' => $unit->geo?->lng,
            'accuracy_m' => $unit->accuracyM,
            'location_captured_at' => $unit->locationCapturedAt?->format(DATE_ATOM),
            'device_id' => $unit->device->deviceId,
            'user_id' => $unit->device->userId,
            'occurred_at' => $unit->occurredAt->format(DATE_ATOM),
            'idempotency_key' => $unit->idempotencyKey,
        ];
    }

    /**
     * Valores de la fila de ubicación en estante vigente de un producto (sin id ni claves de búsqueda: el
     * repositorio hace el upsert por recepción+producto).
     *
     * @return array<string, mixed>
     */
    public function toLocationRow(NodeReception $reception, ShelfLocation $location): array
    {
        return [
            'node_id' => $reception->destinationNodeId(),
            'location' => $location->location,
            'located_at' => $location->locatedAt->format(DATE_ATOM),
            'device_id' => $location->by->deviceId,
            'user_id' => $location->by->userId,
        ];
    }

    /**
     * Reconstruye el agregado completo (con sus bultos recibidos, ubicaciones e informe) desde sus filas.
     */
    public function toDomain(NodeReceptionRecord $record): NodeReception
    {
        $received = [];
        foreach ($record->units as $unitRecord) {
            $hasGeo = $unitRecord->lat !== null && $unitRecord->lng !== null;

            $received[] = ReceivedUnit::record(
                $unitRecord->unit_id,
                $hasGeo ? GeoPoint::of((float) $unitRecord->lat, (float) $unitRecord->lng) : null,
                DeviceContext::of($unitRecord->device_id, $unitRecord->user_id),
                new DateTimeImmutable($unitRecord->occurred_at),
                $unitRecord->idempotency_key,
                $unitRecord->accuracy_m !== null ? (float) $unitRecord->accuracy_m : null,
                $unitRecord->location_captured_at !== null ? new DateTimeImmutable($unitRecord->location_captured_at) : null,
            );
        }

        $locations = [];
        foreach ($record->locations as $locationRecord) {
            $locations[] = ShelfLocation::of(
                $locationRecord->unit_id,
                $locationRecord->location,
                new DateTimeImmutable($locationRecord->located_at),
                DeviceContext::of($locationRecord->device_id, $locationRecord->user_id),
            );
        }

        return NodeReception::reconstitute(
            NodeReceptionId::fromString($record->id),
            $record->shipment_id,
            $record->origin_node_id,
            $record->destination_node_id,
            Corridor::from($record->corridor),
            $record->expected_unit_ids,
            $received,
            NodeReceptionStatus::from($record->status),
            new DateTimeImmutable($record->opened_at),
            $record->reconciled_at !== null ? new DateTimeImmutable($record->reconciled_at) : null,
            $locations,
            $this->reportFrom($record),
        );
    }

    /**
     * Informe de la conciliación desde sus columnas. Un motivo desconocido (dato viejo o manual) cae a
     * «other» en vez de romper la reconstitución.
     */
    private function reportFrom(NodeReceptionRecord $record): ReconciliationReport
    {
        $missing = [];
        foreach ($record->missing_units ?? [] as $row) {
            $missing[] = MissingUnitDeclaration::of(
                $row['unit_id'],
                MissingUnitReason::tryFrom($row['reason']) ?? MissingUnitReason::Other,
            );
        }

        $surplus = [];
        foreach ($record->surplus_units ?? [] as $row) {
            $surplus[] = SurplusUnitDeclaration::of($row['unit_id'], $row['belongs_to_shipment_id']);
        }

        return ReconciliationReport::of(
            $record->reconciliation_notes,
            $record->reconciliation_photo_path,
            $missing,
            $surplus,
        );
    }
}
